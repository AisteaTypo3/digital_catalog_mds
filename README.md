# EXT: digital_catalog

Digital product catalog for TYPO3 13 LTS. The extension renders product list and detail pages for `doc_manager` articles, supports full-text search, system filtering, body-area filtering, clean URLs, AJAX autocomplete, wishlist handling, and separate document sections for eIFU plus product documentation.

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

Go to **Admin Tools -> Maintenance -> Analyze Database Structure** and apply the pending SQL changes. This creates the `tx_digitalcatalog_wishlist` table.

---

## Backend Setup

### Step 1 - Create pages

Create two standard pages in the TYPO3 page tree:

| Page | Purpose | Note |
|---|---|---|
| **Product Catalog** | List + detail view | Note the UID, for example `6423` |
| **Wishlist / Merkliste** | Wishlist + inquiry form | Note the UID, for example `6425` |

### Step 2 - Add the plugin

On both pages, insert a content element:
**Insert Plugin -> Digital Catalog**

The catalog plugin handles `list`, `show`, `suggest`, `toggleWishlist`, `addToWishlist`, and `removeFromWishlist`.
The wishlist page uses the `Wishlist` plugin for the wishlist view plus inquiry submission.

### Step 3 - Include TypoScript

Go to **Site Management -> Sites** and add the static TypoScript include for the extension, or include it manually in your root template:

```typoscript
@import 'EXT:digital_catalog/Configuration/TypoScript/setup.typoscript'
```

---

## TypoScript Configuration

Add these constants to your site's TypoScript constants:

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
      -
        routePath: /
        _controller: 'Catalog::list'
      -
        routePath: '/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments:
          currentPage: currentPage
      -
        routePath: '/area/{bodyRegion}'
        _controller: 'Catalog::list'
        _arguments:
          bodyRegion: bodyRegion
      -
        routePath: '/area/{bodyRegion}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments:
          bodyRegion: bodyRegion
          currentPage: currentPage
      -
        routePath: '/area/{bodyRegion}/system/{system}'
        _controller: 'Catalog::list'
        _arguments:
          bodyRegion: bodyRegion
          system: system
      -
        routePath: '/area/{bodyRegion}/system/{system}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments:
          bodyRegion: bodyRegion
          system: system
          currentPage: currentPage
      -
        routePath: '/system/{system}'
        _controller: 'Catalog::list'
        _arguments:
          system: system
      -
        routePath: '/system/{system}/page-{currentPage}'
        _controller: 'Catalog::list'
        _arguments:
          system: system
          currentPage: currentPage
      -
        routePath: '/article/{article}'
        _controller: 'Catalog::show'
        _arguments:
          article: article
    defaultController: 'Catalog::list'
    defaults:
      currentPage: '1'
      bodyRegion: ''
      system: '0'
      search: ''
    requirements:
      currentPage: \d+
      system: \d+
      article: \d+
    aspects:
      bodyRegion:
        type: StaticValueMapper
        map:
          upper-extremities: upper-extremities
          lower-extremities: lower-extremities
          cmf: cmf
          cmx: cmx
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
      -
        routePath: /
        _controller: 'Catalog::wishlist'
      -
        routePath: '/{submitted}'
        _controller: 'Catalog::wishlist'
        _arguments:
          submitted: submitted
    defaultController: 'Catalog::wishlist'
    requirements:
      submitted: submitted
    aspects:
      submitted:
        type: StaticValueMapper
        map:
          submitted: '1'
```

### Resulting URL patterns

| Action | URL example |
|---|---|
| Catalog list | `/en/catalog/` |
| Paginate | `/en/catalog/page-2` |
| Filter by body area | `/en/catalog/area/cmf` |
| Filter by system | `/en/catalog/system/5` |
| Body area + system | `/en/catalog/area/cmf/system/5` |
| Body area + system + pagination | `/en/catalog/area/cmf/system/5/page-3` |
| Article detail | `/en/catalog/article/42` |
| Wishlist | `/en/merkliste/` |

Search remains a query parameter, because free text is not suitable for a route segment.

---

## Features

### Product Catalog
- Grid layout with article image or placeholder
- Filters for full-text search, `Body Area`, and `System`
- AJAX autocomplete for search suggestions
- Pagination via `georgringer/numbered-pagination`
- Wishlist badge in the header

### Article Detail
- Full-size image with fallback placeholder
- System tags, article number, GTIN/EAN list
- Separate document sections for:
  - eIFU documents
  - Product documentation from the download-center collections
- Direct PDF download plus link to the public eIFU portal
- Add/remove from wishlist button

### Wishlist / Merkliste
- Session-based persistence
- localStorage for optimistic UI updates
- Inquiry form with name, email, company, and message
- Success state after submission

### Product Documentation
- Uses the article's assigned systems to resolve download-center collections
- Shows only `Product Information` and `Product Overview`
- Ignores US download-center setups

---

## Language Files

All UI texts are in XLF translation files.

| File | Language |
|---|---|
| `Resources/Private/Language/locallang.xlf` | English (default) |
| `Resources/Private/Language/de.locallang.xlf` | German |

Add further languages by creating `<lang-code>.locallang.xlf` with translations for all `trans-unit` IDs.

---

## Data Model

This extension reads data from `doc_manager` and related download-center collections. It does not define its own article records.

| Model | Table | Used for |
|---|---|---|
| `Article` | `tx_docmanager_domain_model_article` | Products, article numbers, image, GTINs |
| `System` | `tx_docmanager_domain_model_system` | Filter dropdown, system badges, document lookup |
| `BodyRegion` | `tx_docmanager_domain_model_bodyregion` | Body-area filter |
| `BodySubRegion` | `tx_docmanager_domain_model_bodysubregion` | Mapping between articles and body areas |
| `Document` | `tx_docmanager_domain_model_document` | eIFU documents on the detail page |

### MM relations used
- `tx_docmanager_system_article_mm` links articles to systems
- `tx_docmanager_bodysubregion_article_mm` links articles to body sub-regions

---

## File Structure

```text
packages/digital_catalog/
├── Classes/
│   ├── Controller/
│   │   └── CatalogController.php
│   └── Service/
│       ├── ProductDocumentationService.php
│       └── WishlistService.php
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
│   │   ├── Layouts/Default.html
│   │   ├── Partials/
│   │   │   ├── Article/Card.html
│   │   │   └── Pagination.html
│   │   └── Templates/Catalog/
│   │       ├── List.html
│   │       ├── Show.html
│   │       └── Wishlist.html
│   └── Public/
│       ├── Css/catalog.css
│       └── JavaScript/catalog.js
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
└── ext_tables.sql
```

---

## Troubleshooting

**Filter shows all articles regardless of selection**  
Make sure `Catalog::list` is registered as non-cacheable in `ext_localconf.php`. The filter form uses a plain HTML `<form method="get">`.

**`Service not found` for `CatalogController`**  
Check `Configuration/Services.yaml` and flush TYPO3 caches after changes.

**No articles shown**  
Verify that `storagePid` points to the folder containing the article records.

**Wrong language for documents**  
eIFU documents are matched against the site language. Product documentation uses the language metadata from the download-center collections.

**Wishlist not persisting**  
Check frontend session handling and make sure the page is not served from static cache.
