# EXT: digital_catalog

A minimalist digital product catalog for TYPO3 13 LTS, built on top of the `doc_manager` (eIFU) extension. Displays articles with images, system tags, and linked eIFU documents. Includes a wishlist (Merkliste) with inquiry form.

---

## Requirements

| Dependency | Version |
|---|---|
| TYPO3 | 13.4+ |
| PHP | 8.2+ |
| `medartis/doc-manager` | `@dev` |
| `georgringer/numbered-pagination` | `^4.0` |

Articles, systems and documents are read from the `doc_manager` extension — no separate data import needed.

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

Go to **Admin Tools → Maintenance → Analyze Database Structure** and apply the pending SQL changes.  
This creates the `tx_digitalcatalog_wishlist` table (used for submitted inquiry storage).

---

## Backend Setup

### Step 1 — Create pages

Create two standard pages in the TYPO3 page tree:

| Page | Purpose | Note |
|---|---|---|
| **Product Catalog** | List + detail view | Note the UID (e.g. `6423`) |
| **Wishlist / Merkliste** | Wishlist + inquiry form | Note the UID (e.g. `6424`) |

### Step 2 — Add the plugin

On **both pages**, insert a content element:  
**Insert Plugin → Digital Catalog**

The single plugin instance handles all actions (`list`, `show`, `wishlist`, `addToWishlist`, `removeFromWishlist`, `submitInquiry`).

### Step 3 — Include TypoScript

Go to **Site Management → Sites** and add the static TypoScript include for the extension, or include it manually in your root template:

```
@import 'EXT:digital_catalog/Configuration/TypoScript/setup.typoscript'
```

---

## TypoScript Configuration

Add these constants to your site's TypoScript constants, adjusting the values to match your setup:

```typoscript
plugin.tx_digitalcatalog_catalog {
    settings {
        # UID of the TYPO3 page that holds the article records (sys_folder)
        storagePid = 293

        # Number of articles per page
        itemsPerPage = 24

        # UID of the catalog list/detail page
        catalogPageUid = 6423

        # UID of the wishlist page
        wishlistPageUid = 6424

        # UID of the eIFU detail page (0 = disabled)
        eifuPageUid = 0

        # Base URL for links to the public eIFU portal
        eifuPortalBaseUrl = https://medartis.com
    }
}
```

### Settings reference

| Key | Type | Description |
|---|---|---|
| `storagePid` | `int` | PID of the sys_folder containing articles. Multiple PIDs are **not** supported — use one folder. |
| `itemsPerPage` | `int` | Number of articles shown per page. Default: `24`. |
| `catalogPageUid` | `int` | Target page for all catalog links (list + detail). |
| `wishlistPageUid` | `int` | Target page for the wishlist and inquiry form. |
| `eifuPageUid` | `int` | If set, document links open the internal eIFU detail page. Set to `0` to disable. |
| `eifuPortalBaseUrl` | `string` | Base URL for "Open in eIFU Portal" links on the article detail page. |

---

## Routing (Clean URLs)

Add the following `routeEnhancers` block to your site's `config/sites/<site-identifier>/config.yaml`.  
Replace `6423` and `6424` with your actual page UIDs.

```yaml
routeEnhancers:
  DigitalCatalogCatalog:
    type: Extbase
    extension: DigitalCatalog
    plugin: Catalog
    limitToPages:
      - 6423   # catalog list + detail page
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
    requirements:
      currentPage: \d+
      system: \d+
      article: \d+
    aspects:
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
    plugin: Catalog
    limitToPages:
      - 6425   # wishlist page
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
| Filter by system | `/en/catalog/system/5` |
| Filter + paginate | `/en/catalog/system/5/page-3` |
| Article detail | `/en/catalog/article/42` |
| Wishlist | `/en/merkliste/` |

Search (`?tx_digitalcatalog_catalog[search]=...`) remains a query parameter — free-text search is not suitable for path segments.

---

## Email Configuration (Inquiry Form)

The inquiry form sends a plain-text email when submitted. Configure the sender in your site's TypoScript setup:

```typoscript
plugin.tx_digitalcatalog_catalog {
    settings {
        # Email address that receives inquiry submissions
        inquiryReceiverEmail = sales@example.com
        inquiryReceiverName = Sales Team
    }
}
```

> **Note:** The current `WishlistService` uses the hardcoded receiver from `WishlistService::sendInquiryMail()`. Update that method or extend the settings to override.

---

## Features

### Product Catalog (list view)
- Grid layout with article image (or SVG placeholder if none)
- Filters: **full-text search** (product name + article number) and **System** dropdown
- Pagination via `georgringer/numbered-pagination` — configurable items per page
- Wishlist count badge in the header

### Article Detail (show view)
- Full-size image with fallback placeholder
- System tags, article number, GTIN/EAN list
- **eIFU documents** filtered to the current page language (e.g. only German documents on `/de/`)
- Direct PDF download + link to public eIFU portal
- Add/remove from wishlist button

### Wishlist / Merkliste
- Session-based persistence (TYPO3 frontend user session)
- localStorage for optimistic UI updates (no page reload on toggle)
- Inquiry form (name, email, company, message) sends product list by email
- Success state after submission

---

## Language Files

All UI texts are in XLF translation files — no text is hardcoded in templates.

| File | Language |
|---|---|
| `Resources/Private/Language/locallang.xlf` | English (default) |
| `Resources/Private/Language/de.locallang.xlf` | German |

Add further languages by creating `<lang-code>.locallang.xlf` with translations for all `trans-unit` IDs.

---

## Data Model (doc_manager)

This extension reads data from `doc_manager` — it does **not** define its own article records.

| Model | Table | Used for |
|---|---|---|
| `Article` | `tx_docmanager_domain_model_article` | Products (name, article number, image, GTINs) |
| `System` | `tx_docmanager_domain_model_system` | Filter dropdown, system badges on cards |
| `Document` | `tx_docmanager_domain_model_document` | eIFU documents on detail page |

### MM Relations used
- `tx_docmanager_system_article_mm` — links articles to systems (`uid_local` = article, `uid_foreign` = system)
- `tx_docmanager_bodysubregion_article_mm` — links articles to body sub-regions (not used in filters, reserved for future use)

---

## File Structure

```
packages/digital_catalog/
├── Classes/
│   ├── Controller/
│   │   └── CatalogController.php       # All actions: list, show, wishlist, inquiry
│   └── Service/
│       └── WishlistService.php         # Session handling, email dispatch
├── Configuration/
│   ├── Icons.php                       # Plugin icon registration
│   ├── Services.yaml                   # Autowiring + extbase.controller tag
│   ├── TCA/Overrides/
│   │   └── tt_content.php              # Plugin registration in content wizard
│   └── TypoScript/
│       ├── constants.typoscript        # All configurable settings
│       └── setup.typoscript            # Asset includes
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   ├── locallang.xlf           # EN
│   │   │   └── de.locallang.xlf        # DE
│   │   ├── Layouts/Default.html
│   │   ├── Partials/
│   │   │   ├── Article/Card.html       # Product card
│   │   │   └── Pagination.html         # Numbered pagination
│   │   └── Templates/Catalog/
│   │       ├── List.html               # Catalog grid + filter
│   │       ├── Show.html               # Article detail
│   │       └── Wishlist.html           # Wishlist + inquiry form
│   └── Public/
│       ├── Css/catalog.css
│       ├── Icons/Extension.svg
│       └── JavaScript/catalog.js
├── composer.json
├── ext_emconf.php
├── ext_localconf.php                   # Plugin registration (all actions non-cacheable)
└── ext_tables.sql                      # tx_digitalcatalog_wishlist table
```

---

## Troubleshooting

**Filter shows all articles regardless of selection**  
Make sure all actions are registered as non-cacheable in `ext_localconf.php`. The filter form uses a plain HTML `<form method="get">` (not `f:form`) to avoid TYPO3's `__trustedProperties` HMAC validation failure.

**"Service not found" error for CatalogController**  
Check that `Configuration/Services.yaml` exists and contains the `extbase.controller` tag for `CatalogController`. Run `ddev typo3 cache:flush` after adding it.

**No articles shown**  
Verify `storagePid` in TypoScript constants matches the actual PID of the sys_folder containing articles. Check in TYPO3 backend: click the folder → the PID is shown in the URL.

**Wrong language for documents**  
The document language is matched against the TYPO3 site language code (e.g. `en`, `de`). Documents must have the correct two-letter language code set in `doc_manager`.

**Session / wishlist not persisting**  
TYPO3 13 frontend sessions require an active frontend user session context. Ensure the page is not served from a static file cache (`disableStaticFileCache: false` in `config.yaml` is the relevant setting).
