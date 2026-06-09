# EXT: digital_catalog

Digital product catalog for TYPO3 13 LTS. The extension renders product list and detail pages for `doc_manager` articles, supports full-text search, body-area filtering, system filtering, product-type filtering, clean URLs, AJAX autocomplete, wishlist handling, inquiry emails, and separate document sections for eIFU plus product documentation.

---

## Requirements

| Dependency | Version |
|---|---|
| TYPO3 | 13.4+ |
| PHP | 8.2+ |
| `medartis/doc-manager` | `@dev` |
| `georgringer/numbered-pagination` | `^4.0` |

Articles, systems, and eIFU documents are read from `doc_manager`. Product documentation is resolved from the configured download-center collections on the system pages.

---

## Installation

### 1. Register in root `composer.json`

```json
{
  "require": {
    "medartis/digital-catalog": "@dev"
  },
  "repositories": [
    {
      "type": "path",
      "url": "packages/*"
    }
  ]
}
```

```bash
ddev composer require medartis/digital-catalog:@dev
```

### 2. Activate the extension

```bash
ddev typo3 extension:activate digital_catalog
```

### 3. Update the database

Go to **Admin Tools → Maintenance → Analyze Database Structure** and apply any pending SQL changes.

---

## Backend Setup

### Step 1 — Create pages

Create two standard pages in the TYPO3 page tree:

| Page | Purpose | Note |
|---|---|---|
| **Product Catalog** | List + detail view | Note the UID, for example `6423` |
| **Wishlist / Merkliste** | Wishlist + inquiry form | Note the UID, for example `6425` |

### Step 2 — Add the plugin

On both pages, insert a content element: **Insert Plugin → Digital Catalog**

The catalog plugin handles `list`, `show`, `suggest`, `toggleWishlist`, `addToWishlist`, and `removeFromWishlist`. The wishlist page uses the `Wishlist` plugin for the wishlist view plus inquiry submission.

### Step 3 — Include TypoScript

Go to **Site Management → Sites** and add the static TypoScript include for the extension, or include it manually in your root template:

```typoscript
@import 'EXT:digital_catalog/Configuration/TypoScript/setup.typoscript'
```

---

## TypoScript Configuration

```typoscript
plugin.tx_digitalcatalog_catalog {
    settings {
        storagePid = 293
        itemsPerPage = 24
        catalogPageUid = 6423
        wishlistPageUid = 6425
        eifuPageUid = 0
        eifuPortalBaseUrl = https://medartis.com
    }
}
```

### Settings reference

| Key | Type | Description |
|---|---|---|
| `storagePid` | `int` | PID of the sys_folder containing articles. Multiple PIDs are not supported. |
| `itemsPerPage` | `int` | Number of articles shown per page. Default: `24`. |
| `catalogPageUid` | `int` | Target page for all catalog links. |
| `wishlistPageUid` | `int` | Target page for the wishlist and inquiry form. |
| `eifuPageUid` | `int` | If set, document links open the internal eIFU detail page. Set to `0` to disable. |
| `eifuPortalBaseUrl` | `string` | Base URL for public eIFU portal links on the article detail page. |

---

## Routing

Add the following `routeEnhancers` block to your site's `config/sites/<site-identifier>/config.yaml`. Replace the page UIDs with your own.

```yaml
routeEnhancers:
  DigitalCatalogCatalog:
    type: Extbase
    extension: DigitalCatalog
    plugin: Catalog
    limitToPages:
      - 6423
    routes:
      - { routePath: /, _controller: 'Catalog::list' }
      - routePath: '/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { currentPage: currentPage }
      - routePath: '/area/{bodyRegion}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion }
      - routePath: '/area/{bodyRegion}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, currentPage: currentPage }
      - routePath: '/area/{bodyRegion}/system/{system}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, system: system }
      - routePath: '/area/{bodyRegion}/system/{system}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, system: system, currentPage: currentPage }
      - routePath: '/system/{system}'
        _controller: 'Catalog::list'
        _arguments: { system: system }
      - routePath: '/system/{system}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { system: system, currentPage: currentPage }
      - routePath: '/type/{productType}'
        _controller: 'Catalog::list'
        _arguments: { productType: type }
      - routePath: '/type/{productType}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { productType: type, currentPage: currentPage }
      - routePath: '/area/{bodyRegion}/type/{productType}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, productType: type }
      - routePath: '/area/{bodyRegion}/type/{productType}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, productType: type, currentPage: currentPage }
      - routePath: '/area/{bodyRegion}/system/{system}/type/{productType}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, system: system, productType: type }
      - routePath: '/area/{bodyRegion}/system/{system}/type/{productType}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { bodyRegion: bodyRegion, system: system, productType: type, currentPage: currentPage }
      - routePath: '/system/{system}/type/{productType}'
        _controller: 'Catalog::list'
        _arguments: { system: system, productType: type }
      - routePath: '/system/{system}/type/{productType}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments: { system: system, productType: type, currentPage: currentPage }
      - routePath: '/article/{article}'
        _controller: 'Catalog::show'
        _arguments: { article: article }
    defaultController: 'Catalog::list'
    defaults:
      currentPage: '1'
      bodyRegion: ''
      system: '0'
      search: ''
      type: ''
    requirements:
      currentPage: \d+
      system: \d+
      article: \d+
      productType: 'screw|plate|nail|k-wire|pin|wire|washer|instrument'
    aspects:
      bodyRegion:
        type: StaticValueMapper
        map:
          upper-extremities: upper-extremities
          lower-extremities: lower-extremities
          cmf: cmf
          cmx: cmx
      productType:
        type: StaticValueMapper
        map:
          screw: screw
          plate: plate
          nail: nail
          k-wire: k-wire
          pin: pin
          wire: wire
          washer: washer
          instrument: instrument
      currentPage:
        type: StaticRangeMapper
        start: '1'
        end: '999'
      system:
        type: PersistedAliasMapper
        tableName: tx_docmanager_domain_model_system
        routeFieldName: uid
      article:
        type: PersistedAliasMapper
        tableName: tx_docmanager_domain_model_article
        routeFieldName: uid
  DigitalCatalogWishlist:
    type: Extbase
    extension: DigitalCatalog
    plugin: Wishlist
    limitToPages:
      - 6425
    routes:
      - { routePath: /, _controller: 'Catalog::wishlist' }
      - routePath: '/{submitted}'
        _controller: 'Catalog::wishlist'
        _arguments: { submitted: submitted }
    defaultController: 'Catalog::wishlist'
    requirements:
      submitted: submitted
    aspects:
      submitted:
        type: StaticValueMapper
        map:
          submitted: '1'
```

### Routing note — `productType` vs `type`

The URL path segment is named `{productType}` in the route definitions to avoid a YAML parsing conflict: the `aspects` block uses `type:` as a reserved property key for specifying the mapper class. Using `productType` as the segment name and mapping it to the plugin argument `type` via `_arguments: { productType: type }` keeps the YAML valid while the controller still receives the value as `type`.

### Resulting URL patterns

| Active filters | URL example |
|---|---|
| None | `/en/catalog/` |
| Page 2 | `/en/catalog/page-2` |
| Body area | `/en/catalog/area/cmf` |
| Body area + page | `/en/catalog/area/cmf/page-3` |
| Body area + system | `/en/catalog/area/cmf/system/5` |
| Body area + system + page | `/en/catalog/area/cmf/system/5/page-2` |
| System only | `/en/catalog/system/5` |
| Product type | `/en/catalog/type/screw` |
| Product type + page | `/en/catalog/type/screw/page-2` |
| Body area + product type | `/en/catalog/area/upper-extremities/type/plate` |
| Body area + system + product type | `/en/catalog/area/upper-extremities/system/5/type/plate` |
| Article detail | `/en/catalog/article/42` |
| Wishlist | `/en/merkliste/` |

Search (`?tx_digitalcatalog_catalog[search]=…`) remains a query parameter because free text is not suitable for a route segment.

---

## Features

### Product Catalog

- Responsive grid layout with article image or SVG placeholder
- **Full-text search** — queries `productName` and `articleNumber`, with AJAX autocomplete (debounced, keyboard-navigable, abortable on new input)
- **Body-area filter** — maps URL slugs (`upper-extremities`, `lower-extremities`, `cmf`, `cmx`) to `BodyRegion` records; dropdown only shows areas that have articles
- **System filter** — dynamically limited by the selected body area (see [System filter and body-area mapping](#system-filter-and-body-area-mapping))
- **Product-type filter** — keyword-based classification of articles; dropdown only shows types that have results for the current filter state
- Pagination via `georgringer/numbered-pagination`
- Wishlist badge with animated count in the header

### Article Detail

- Full-size image (`loading="lazy"`) with fallback placeholder
- System tags, article number, GTIN/EAN list
- Separate document sections for eIFU documents and product documentation
- Direct PDF download plus link to the public eIFU portal
- Add/remove from wishlist

### Wishlist and Inquiry Form

- Session-based persistence (JSON with quantity per article)
- `localStorage` for optimistic UI updates without page reload
- AJAX wishlist toggle and quantity stepper
- Inquiry form with name, email, company, and message
- Honeypot field for bot protection (see [Security](#security))
- Excel attachment generated with PhpSpreadsheet, sent as email attachment
- Email body rendered via Fluid template (`Templates/Email/Inquiry.html`)
- Success redirect after submission

### Product Type Detection

Product types are determined at runtime by matching keywords in `article.productName`. No additional database column is required. The logic lives in `Classes/Enum/ProductType.php`.

Supported types: `Screw`, `Plate`, `Nail`, `K-Wire`, `Pin`, `Wire`, `Washer`, `Instrument`.

Instrument keywords are checked **first** to prevent false positives: a product named *"Plate Cutting Pliers"* would otherwise be misclassified as `Plate` before `Instrument` is checked.

```php
// Usage example
$type = ProductType::fromProductName('Distal Radius Plate 2.5');
// → ProductType::Plate

$type = ProductType::fromProductName('Plate Bending Pliers');
// → ProductType::Instrument   ← checked before Plate
```

The `ProductTypeViewHelper` can be used in any Fluid template:

```html
{dc:productType(name: article.productName)}              <!-- value: 'plate' -->
{dc:productType(name: article.productName, output: 'label')} <!-- 'Plate' -->
{dc:productType(name: article.productName, output: 'css')}   <!-- 'dc-type--plate' -->
```

---

## System Filter and Body-Area Mapping

The system dropdown is filtered client-side when a body area is selected, so only the systems relevant to that area are visible.

### How it works

On every catalog page request, `CatalogController::findSystemsByBodyRegion()` runs a single SQL query that joins five tables:

```
bodyregion → bodysubregion → bodysubregion_article_mm
           → article → system_article_mm → system
```

The result is a map of `lowercase body-region title → sorted list of system titles`:

```json
{
  "upper extremities": ["Arthrodesis", "CCS", "Clavicle", "Distal Radius", "..."],
  "lower extremities": ["3.5 Straight Plates", "All-in-One Staple", "Ankle", "..."],
  "cmf":               ["BFS 0.9/1.2,1.5", "CFS 1.8", "MODUS", "MODUS 2 IMF", "..."],
  "cmx":               ["Ankle", "CMX Hardware", "CMX Software", "Forearm", "..."]
}
```

This JSON is written into the filter form's `data-systems-by-area` attribute:

```html
<form class="dc-filter__form" data-systems-by-area="{systemsByBodyRegion}" ...>
```

The JavaScript (`initBodyRegionSystemFilter` in `catalog.js`) reads the attribute on page load and rebuilds the system dropdown whenever the body-area selection changes:

```javascript
const systemsByArea = JSON.parse(form.dataset.systemsByArea || '{}');
```

### Why this approach

Previously the system-to-area mapping was hardcoded in `catalog.js` as a static JavaScript object. That caused two problems:

1. **Stale data** — any change to systems or body regions in the backend required a manual code edit in the JS file.
2. **Duplication** — the list of valid systems per area existed only in one place (the JS), disconnected from the actual DB content.

The current approach derives the mapping directly from the database on every request, so it is always in sync with the TYPO3 backend without any additional maintenance.

### Adding a new body area

1. Create the `BodyRegion` record in the TYPO3 backend and assign articles to it via `BodySubRegion` records.
2. Add the slug to `CatalogController::BODY_REGION_SLUGS` and the template's body-area dropdown in `List.html`.
3. Add the slug to the `bodyRegion` `StaticValueMapper` in `config.yaml` and flush the TYPO3 cache.

No changes to `catalog.js` are required — the system dropdown will automatically reflect the new area's systems.

---

## Security

### Honeypot (inquiry form)

The inquiry form contains a visually hidden `website` field (`.dc-honeypot`, positioned off-screen via CSS). The field is invisible to real users but is filled by most automated bots. If the field arrives non-empty, `submitInquiryAction` silently redirects back to the wishlist page without sending any email or showing an error.

```php
// CatalogController::submitInquiryAction
$website = trim($this->request->getArgument('website'));
if ($website !== '') {
    return $this->redirect('wishlist'); // silent reject
}
```

TYPO3 Extbase's `f:form` ViewHelper additionally generates a `__trustedProperties` HMAC token automatically on every render, which prevents mass-assignment attacks on Extbase model properties.

### Input handling

- Email addresses are validated with `filter_var($email, FILTER_VALIDATE_EMAIL)` before any email is sent.
- All database access uses TYPO3's `QueryBuilder` with named parameters — no raw SQL interpolation.
- Excel temp files are created with `tempnam()` and deleted immediately after the email is dispatched.

---

## Data Model

This extension reads data from `doc_manager`. It does not define its own article records.

| Model | Table | Used for |
|---|---|---|
| `Article` | `tx_docmanager_domain_model_article` | Products, article numbers, image, GTINs |
| `System` | `tx_docmanager_domain_model_system` | Filter dropdown, system badges, document lookup |
| `BodyRegion` | `tx_docmanager_domain_model_bodyregion` | Body-area filter |
| `BodySubRegion` | `tx_docmanager_domain_model_bodysubregion` | Mapping between articles and body areas |
| `Document` | `tx_docmanager_domain_model_document` | eIFU documents on the detail page |

### MM relations used

- `tx_docmanager_system_article_mm` — links articles to systems (`uid_local` = article, `uid_foreign` = system)
- `tx_docmanager_bodysubregion_article_mm` — links articles to body sub-regions (`uid_local` = article, `uid_foreign` = sub-region)

---

## Language Files

All UI texts are in XLF translation files.

| File | Language |
|---|---|
| `Resources/Private/Language/locallang.xlf` | English (default) |
| `Resources/Private/Language/de.locallang.xlf` | German |

Add further languages by creating `<lang-code>.locallang.xlf` with translations for all `trans-unit` IDs.

---

## File Structure

```text
packages/digital_catalog/
├── Classes/
│   ├── Controller/
│   │   └── CatalogController.php        Main controller (list, show, wishlist, suggest, …)
│   ├── Enum/
│   │   └── ProductType.php              Backed enum; keyword-based product-type detection
│   ├── Service/
│   │   ├── ProductDocumentationService.php  Resolves docs from download-center collections
│   │   └── WishlistService.php          Session wishlist, Excel generation, inquiry email
│   └── ViewHelpers/
│       └── ProductTypeViewHelper.php    {dc:productType(name: …, output: 'value|label|css')}
├── Configuration/
│   ├── Icons.php
│   ├── Services.yaml
│   ├── TCA/Overrides/
│   │   └── tt_content.php
│   └── TypoScript/
│       ├── constants.typoscript
│       └── setup.typoscript
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   ├── locallang.xlf
│   │   │   └── de.locallang.xlf
│   │   ├── Layouts/
│   │   │   └── Default.html
│   │   ├── Partials/
│   │   │   ├── Article/Card.html        Product card partial
│   │   │   └── Pagination.html          Numbered pagination with filter passthrough
│   │   └── Templates/
│   │       ├── Catalog/
│   │       │   ├── List.html            Catalog grid + filter form
│   │       │   ├── Show.html            Article detail page
│   │       │   └── Wishlist.html        Wishlist + inquiry form
│   │       └── Email/
│   │           └── Inquiry.html         Fluid-rendered inquiry email body
│   └── Public/
│       ├── Css/catalog.css
│       └── JavaScript/catalog.js
├── composer.json
├── ext_emconf.php
└── ext_localconf.php
```

---

## Troubleshooting

**Filter shows all articles regardless of selection**
Make sure `Catalog::list` is registered as non-cacheable in `ext_localconf.php`. The filter form uses a plain HTML `<form method="get">`.

**System dropdown not filtered by body area**
Check that the `data-systems-by-area` attribute is present on the `.dc-filter__form` element (inspect the page source). If it is empty (`{}`), the `findSystemsByBodyRegion` query returned no rows — verify the body-region and sub-region records are properly linked to articles in the backend.

**Type filter dropdown is missing**
The dropdown is only rendered when `availableTypeOptions` is non-empty. If no articles match any known type keyword, the dropdown is hidden. Verify the article `productName` values contain recognisable keywords (see `ProductType::rules()`).

**`Service not found` for `CatalogController`**
Check `Configuration/Services.yaml` and flush TYPO3 caches after any changes.

**No articles shown**
Verify that `storagePid` points to the folder containing the article records.

**Wrong language for documents**
eIFU documents are matched against the site language code. Product documentation uses the language metadata from the download-center collections.

**Wishlist not persisting**
Check frontend session handling and make sure the page is not served from static cache.

**Inquiry email not sent after form submission**
Confirm the receiver address is set in the site configuration (`contactReceiverEmail`) and that the TYPO3 mail transport is configured in `LocalConfiguration.php`.
