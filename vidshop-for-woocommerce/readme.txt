=== VidShop – Shoppable Videos for WooCommerce ===
Contributors: wpcreatix
Donate link: https://wpcreatix.com/
Tags: shoppable videos, woocommerce videos, video commerce, product videos, mobile shopping
Stable tag: 1.2.0
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Engage customers with swipeable shoppable videos, seamless checkout, and powerful analytics for WooCommerce.

== Description ==

**VidShop Transforms Ecommerce with Interactive Shoppable Videos**

VidShop brings the engaging experience of social media videos directly into your WooCommerce store, creating an immersive, swipe-driven shopping journey. Customers can effortlessly browse, interact with products, and make instant purchases—all within the video.

Ideal for fashion brands, beauty retailers, electronics stores, home décor merchants, and any business seeking next-level video commerce.

🎥 **Try VidShop Live:** [Interactive Demo](https://vidshop.wpcreatix.com/?utm_campaign=vidshop-wordpress-org&utm_medium=demo_link&utm_source=WordPress.org)

🚀 **Upgrade to Pro:** [Get Premium Features](https://wpcreatix.com/?utm_campaign=vidshop-wordpress-org&utm_medium=upgrade_link&utm_source=WordPress.org)

## 🚀 Why Choose VidShop?

✅ **Social Media-Style Navigation** – Swipe videos for engaging, app-like shopping.
✅ **Seamless Purchase Experience** – Instantly buy products without leaving the video.
✅ **In-Depth Analytics** – Track detailed video interactions and product performance.
✅ **Mobile-Optimized** – Designed specifically for smartphone users.
✅ **Easy to Use** – No coding skills required to set up stunning shoppable videos.
✅ **WooCommerce Native** – Perfectly integrates with your existing WooCommerce store.

## 🎯 Key Features

### 📊 Analytics Dashboard

* **Total & Unique Views**
* **Average & Total View Time**
* **Total & Unique Likes**
* **Add-to-Cart Metrics**
* **Product View Tracking**
* **Top Videos & Products Insights**

### 🎬 Intuitive Video Management

* **Publish, Draft, Trash Status Management**
* **Easy Video Upload** (WordPress Media Library & Custom URLs)
* **Product Linking & Association**
* **Bulk Actions & Quick Editing**

### ⚙️ Smart Shortcode Generator

* Select videos: All or specific IDs
* Customize colors and layouts
* Easy copy-paste into pages/posts
* Multiple layouts: Grid, Carousel, and Inline (TikTok-style)

### 📱 Engaging Frontend Experience

* **Swipeable Video Interface**
* **Interactive Product Circles**
* **Social Proof with View Counts & Likes**
* **Real-Time Shopping Cart**
* **Product Variations & Instant Checkout**
* **Mobile & Desktop Optimized**

### 🛒 Product Integration

* Unlimited products per video
* Variable product support
* Live inventory synchronization
* Quick add-to-cart functionality

### 🎨 Brand Customization

* Color schemes aligned with your brand identity
* Responsive layouts and smooth animations
* Touch and mouse-friendly interactions

## 🔥 Easy 4-Step Setup

**Step 1: Add Your Video**

* Upload or link your video and set a thumbnail.

**Step 2: Connect Products**

* Select relevant WooCommerce products to link.

**Step 3: Generate Shortcode**

* Customize your display (video selection, layout, colors).

**Step 4: Go Live**

* Paste shortcode anywhere on your website to showcase videos instantly.

**Example Implementation:**
```
[vsfw-videos videos="all" type="grid" color-schema="#1e40af"]
[vsfw-videos videos="123,456,789" type="carousel" color-schema="#e11d48"]
[vsfw-videos videos="all" type="inline" color-schema="#10b981"]
[vsfw-videos videos="all" type="stories" color-schema="#1e40af" autoplay="yes" loop="yes"]
```

## ⚡ Performance & Compatibility

* Optimized for speed (lazy loading & caching)
* Compatible with popular caching & CDN solutions
* Mobile-first responsive design
* Secure, clean code with extensive hooks for developers
* Fully translation-ready

## 🌟 Industries Benefiting from VidShop

* **Fashion & Apparel**: Showcase products in action.
* **Beauty & Cosmetics**: Demonstrate product transformations.
* **Home & Décor**: Present real-world product placements.
* **Electronics & Tech**: Highlight features and unboxing.
* **Lifestyle Products**: Engage emotionally and increase sales.

== Installation ==

**Quick Setup (Recommended)**

1. Install from Plugins > Add New (search "VidShop")
2. Activate and verify WooCommerce is active
3. Configure basic settings (VidShop > Settings)
4. Create your first video via VidShop > Add New

**Manual Installation**

1. Upload the plugin ZIP to `/wp-content/plugins/vidshop-for-woocommerce/`
2. Activate via Plugins menu
3. Configure in VidShop > Settings

== Frequently Asked Questions ==

**Is video editing experience required?**
No! Simply upload your existing videos, link products, and you're ready to go.

**Can purchases be completed without leaving the video?**
Yes! VidShop provides an uninterrupted, frictionless shopping experience.

**Are swipe features mobile-only?**
Swipe navigation is optimized for mobile, but desktop users can easily navigate with mouse.

**Does VidShop support variable products?**
Absolutely. Customers can select variations directly within the video interface.

**Where can I display videos?**
Use shortcodes anywhere on your site, including pages, posts, and widgets.

**Will VidShop slow down my site?**
No. VidShop is performance-focused, ensuring fast load times.

== Screenshots ==

1. Analytics Dashboard – Gain detailed insights into video performance
2. Video Management – Easily manage and edit videos
3. Video Creation Form – Simple and intuitive video setup
4. Shortcode Generator – Customize your video presentation
5. Modern Video Player – Engaging swipe navigation and product integration
6. Interactive Product Shopping – Seamless product browsing
7. Mobile Experience – Optimized for mobile shopping
8. Live Cart & Checkout – Streamlined shopping process

== Changelog ==

= 1.2.0 - Ordering, Price Formatting, Display Controls & Extensibility Hooks =

* **New:** Added an extensibility layer: third-party add-ons can register PHP `apply_filters` / `do_action` and JS `@wordpress/hooks` filters to extend admin surfaces, REST responses, and shortcodes without modifying plugin code.
* **New:** JS filters for third-party admin UI extensions: `vsfw.admin.pages`, `vsfw.admin.home.widgets`, `vsfw.admin.pages.videoStats.body`, `vsfw.admin.shortcodeModal.generatedShortcode`, `vsfw.admin.pages.tags.body`, `vsfw.admin.videoForm.tagsSection`, `vsfw.admin.videosList.tagsFilter`.
* **New:** PHP filters and actions on `/vsfw/v1/videos`: `vsfw_video_list_query_params`, `vsfw_video_list_query`, `vsfw_video_response_data`, `vsfw_video_list_response`, `vsfw_video_saved`.
* **New:** PHP filters: `vsfw_video_shortcode_parsed_atts`, `vsfw_tags_filtering_enabled`, `vsfw_admin_localized_data`, `vsfw_frontend_localized_data`.
* **New:** Shortcode generator now supports drag-and-drop reordering for specific videos.
* **New:** `orderby` (date, title, id, random) and `order` (asc, desc) shortcode attributes for "all videos".
* **New:** Show / hide view count and like count, both as toggles in the shortcode generator and as `show-views` / `show-likes` shortcode attributes.
* **New:** Price formatting now mirrors WooCommerce — currency symbol position, thousands/decimal separators and decimal count are honored everywhere VidShop displays a price (product cards, modals, dashboard analytics).
* **New:** Price display settings page (VidShop → Settings) lets you override WooCommerce's defaults just for VidShop, with a one-click "Reset to WooCommerce defaults" button.
* **New:** `tags="..."` / `tags-operator="OR|AND"` shortcode attributes; honored when an add-on plugin enables tag filtering by returning `true` from the `vsfw_tags_filtering_enabled` filter.
* **Improved:** Admin bundle now depends on `wp-hooks` so extensions can register JS filters reliably.
* **Improved:** Settings REST endpoint now correctly persists `null`-friendly defaults.

= 1.1.5 - Security Update =

* **Security:** Added whitelist validation for fields parameter
* **Security:** Added integer sanitization for ids parameter
* **Security:** Implemented prepared statements for all raw SQL queries
* **Security:** Added column name validation in Query Builder

= 1.1.4 - Plugin URI Update =

* **Updated:** Added proper Plugin URI for WordPress.org directory listing

= 1.1.3 - RTL Carousel Navigation Fix & Translations =

* **Fixed:** Carousel navigation arrows now display correctly in RTL (Right-to-Left) languages - Arrow icons are properly mirrored for Arabic, Hebrew, and other RTL languages
* **Improved:** Better RTL support for horizontal and stories carousel layouts
* **New:** Added Arabic (ar) translation - Full plugin translation for Arabic-speaking users
* **New:** Added German (de_DE) translation - Full plugin translation for German-speaking users
* **New:** Added Brazilian Portuguese (pt_BR) translation - Full plugin translation for Brazilian Portuguese-speaking users

= 1.1.2 - WordPress 6.9 Compatibility & Aspect Ratio Fix =

* **New:** WordPress 6.9 compatibility - Fully tested and compatible with the latest WordPress version
* **Fixed:** Videos Aspect Ratio Issue - Videos in grid and carousel layouts now maintain proper 9:16 aspect ratio on all screen sizes, preventing videos from appearing square on large screens
* **Enhanced:** Responsive video sizing - Better video proportions on desktop, tablet, and mobile devices with viewport-aware scaling

= 1.1.1 - Autoplay Fix for Grid and Carousel =

* Fixed: Autoplay sequencing now properly advances to next video when current video ends in grid and carousel layouts

= 1.1.0 - Autoplay, Loop, and Stories Carousel =

* New: Stories Carousel layout (stories-style interactive cards in a horizontal carousel)
* New: Autoplay and Loop options for all layouts (Grid, Carousel, Stories, Inline) - Automatically plays videos in sequence when modal is open, with option to restart from first video after the last ends
* New: Modal-aware video behavior - Videos loop on current video when product/cart modal is open, then resume sequencing when closed
* New: Custom URL redirect option for post-add-to-cart action - Redirect to any custom URL after adding product to cart, supports full URLs and relative paths with real-time validation
* New: Stories exclusive audio - Only one video unmuted at a time for better user experience
* New: Play on Hover for Stories (effective when autoplay="no")
* New: Auto-open product details for grid/carousel layouts - Automatically opens the first product in the video modal when modal is opened
* Improved: Modal interaction handling - Pauses video when modal opens, resumes on modal close
* Improved: Inline Carousel autoplay with sequenced playback and modal-aware looping behavior
* Improved: Modals render via portals with content scaling using --font-scale
* Improved: Product/cart modals in Stories open only for the current video
* Fixed: Video loading and playback consistency across all layouts
* Performance: Lazy-load current/adjacent slides with next-slide peek
* Performance: Optimized event listeners and state management

= 1.0.3 - Enhanced Product Interaction & Button Improvements =

* **New:** Add to Cart Action option - Choose between showing product details modal or opening product page in new tab
* **Fixed:** Button sizing consistency - Improved button appearance across all themes and layouts
* **Fixed:** Cart modal button styling - Enhanced visual consistency for quantity and close buttons
* **Fixed:** Video controls button dimensions - Standardized sizing for better touch interaction
* **Improved:** Product card flexibility - Better control over customer shopping flow
* **Enhanced:** Theme compatibility - Buttons now maintain proper sizing regardless of theme CSS overrides

= 1.0.2 - Major Feature Update & Enhanced UX =

* **New:** Carousel layout - Display videos in a beautiful horizontal scrolling carousel
* **New:** Inline layout (TikTok-style) - Vertical scrolling video feed for social media-like experience
* **New:** Custom color schema support - Full brand customization with unlimited color options
* **New:** Responsive customizations - Advanced responsive controls for different screen sizes
* **New:** Single video analytics - Detailed performance metrics for individual videos
* **New:** Play on hover option - Videos can automatically play when users hover over them
* **Enhanced:** Admin dashboard UX - Improved interface with better navigation and user experience
* **Improved:** Layout flexibility with multiple display options for different use cases
* **Optimized:** Performance improvements for better loading times across all layouts

= 1.0.1 - Enhanced Compatibility & User Experience =

* **Fixed:** Dark mode browser compatibility issues - video controls, navigation, and close buttons now display consistently regardless of browser color scheme
* **Fixed:** Button sizing and padding issues - added protection against theme overrides to ensure consistent button appearance
* **Enhanced:** Admin interface icons for better visual clarity and user experience
* **Improved:** Theme compatibility - components now maintain consistent styling across all WordPress themes

= 1.0.0 - Initial Release =

* Professional video management and seamless WooCommerce integration
* Detailed analytics dashboard with video and product insights
* Interactive frontend video shopping with mobile-first design
* Optimized for performance, security, and ease-of-use

== Upgrade Notice ==

= 1.0.3 =
Important update with new Add to Cart Action option for better customer flow control and critical button sizing fixes for improved theme compatibility. Recommended upgrade for all users.

= 1.0.2 =
Major update with new carousel and inline (TikTok-style) layouts, enhanced analytics, custom color schemas, and improved admin dashboard UX. Upgrade now for the most comprehensive video commerce experience.

= 1.0.0 =
Launch your WooCommerce store into the future of video commerce. VidShop delivers a premium shoppable video experience that enhances customer engagement and boosts conversions instantly.
