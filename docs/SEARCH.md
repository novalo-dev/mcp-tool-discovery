# Search

`ToolSearch` finds the tools that answer a query, the best first. It is what
`search_tools` uses, and it can be used on its own:

```php
$found = (new ToolSearch($visibleTools))->search('change the status of an order');
```

---

## How a tool is scored

Three signals, added up.

**1. Text relevance (BM25F).** Each tool is a document of four fields:

| Field | Weight | Text |
|---|---|---|
| name | 3 | the name, split (`set_order_status` gives "set order status"), shared prefix left out |
| title | 2 | `title`, or the title of the annotations |
| description | 1 | `description` |
| parameters | 0.5 | the names and descriptions of the `inputSchema` properties |

BM25F gives each field its weight and normalises each field's length on its
own. A rare word weighs more than a common one: on a store server, "refund"
counts more than "order", which half the tools mention. A word repeated earns
less each time.

**2. Verb intent.** Tool names are conventionally `verb_object`. A query says
"change the status" or "turn off a product", and no text match links "change"
to `set`. `VerbIntent` holds plain English action words and the verbs a tool
name may start with for each; a tool whose verb matches gains a bonus.

**3. Object coverage.** The share of the name's object words ("order",
"status") the query names. It tells `set_product_active` from
`set_module_active` when the query says "product".

Then the ranking is **cut** at 60% of the best score, and **limited** to 10
tools. Equal scores keep the host's order, so a query always gives the same
answer.

## Words and trigrams

The Analyzer splits snake_case, kebab-case, dots and camelCase, lowercases,
folds accented Latin letters to ASCII without any extension, and leaves out
English stop words and numbers ("order 1042" is about an order, not about
1042).

Each word also gives its trigrams, padded: "status" gives ` st`, `sta`, `tat`,
`atu`, `tus`, `us `. Trigram matches count at 0.35 of word matches. They let
"statuses" meet "status" and "categories" meet "category" with no rule of any
language.

**No stemmer, on purpose.** A Porter stemmer was measured and made the search
worse: folding "categories" to "categori" erases the sign that a plural query
wants the list tool, and more tools tie.

## Synonyms

The library holds no business vocabulary. A host that knows its clients say
"voucher" for a cart rule, or "parcel" for a shipment, gives a map:

```php
new SearchOptions(synonyms: ['voucher' => 'cart rule', 'parcel' => 'shipment tracking']);
```

The synonyms are added to the query; the caller's own words still count.

Better still, put those words in the tool descriptions: every client reads them,
even one that never searches.

---

## The measures

| Set | Tools | Queries | Right tool first | Top 3 |
|---|---|---|---|---|
| Fixture server of this repository, queries written before any tuning | 50 | 30 | 26 | 27 |
| A real server, queries used while designing the ranking | 135 | 75 | 65 | 70 |
| The keyword search it replaced, same real server | 135 | 75 | 26 | 45 |

The fixture set is the honest one: its queries were written and run once,
against the finished ranking, before anything was adjusted. Its misses are
vocabulary the tools do not use ("parcel" for a shipment, "admin rights" for a
role): synonyms or descriptions fix those, not weights.

On the real server, raising the weights further reached 37/40 on the queries
used to choose them and stayed lower on the others: the gap a text search
leaves. The rest is the description of each tool.

## Changing the ranking

- Run `composer benchmark`. A change must not lower the fixture score:
  `tests/Search/BenchmarkTest.php` holds the floors.
- Add queries to `tests/Fixtures/queries.json` **before** running them on your
  change, or the benchmark only measures what you tuned.
- Give the numbers before and after in the pull request, and update this page
  and the README.
