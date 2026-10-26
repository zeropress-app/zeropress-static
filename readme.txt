=== ZeroPress Static ===
Contributors: laelbe
Tags: jamstack, static site, publish
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Keep writing in WordPress and publish your posts and pages as a static site.

== Description ==

Let your readers spend more time with your stories and less time waiting.

ZeroPress Static helps you publish your WordPress posts and pages to a separate static site. Visitors read prebuilt pages, so WordPress does not need to generate each page when someone opens it.

[View the live demo](https://starter-wxr.demo.zeropress.app/) to explore an example of the published site.

Your static site uses a ZeroPress theme, with support for search, archives and RSS. Your active WordPress theme is not exported.

For your first site, we recommend GitHub publishing with Cloudflare hosting. Set up [ZeroPress WXR Starter](https://github.com/zeropress-app/zeropress-starter-wxr), the companion project that builds your static site, using the walkthrough below. After that, publish your content from WordPress to GitHub; Cloudflare builds and deploys your updates automatically.

= Features =

* Publish posts, pages and selected classic WordPress menus.
* Prepare and publish from a dialog in the classic or block editor.
* Publish to GitHub to update your connected static site automatically.
* Use different addresses for your media and WordPress Site Icon when needed.
* Search, RSS, archives, footer credits and newsletter signup links.
* Optional WordPress comments, with separate display and writing settings and Cloudflare Turnstile support.
* Optional protection that limits public access to WordPress APIs.
* English, Korean, Japanese, Spanish (Spain), German, French and Portuguese (Brazil) admin screens.

All options are in **ZeroPress → Settings**. The Site section shows the title, description, language, timezone, posts per page and icon read from WordPress. Save settings, then open **ZeroPress → Publish** to prepare the files.

= External services =

**GitHub**

If you configure GitHub publishing, the plugin connects to [GitHub](https://github.com/) when you test the connection, prepare publishing data or publish. It uses your saved GitHub token to access the selected repository. Publishing sends your public posts and pages, selected menus, media information and site settings. The plugin does not collect usage statistics or publish on a schedule.

* [GitHub Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
* [GitHub Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

**Cloudflare Turnstile**

Cloudflare Turnstile is off by default. If you enable comment writing and select **Use Cloudflare Turnstile**, visitors' browsers connect to Cloudflare to display its verification widget.

When a visitor submits a comment with Turnstile enabled, WordPress sends the verification token and your Turnstile Secret Key to Cloudflare to check the result. Reading comments and using the built-in bot protection do not use this service.

* [Cloudflare Terms of Service](https://www.cloudflare.com/terms/)
* [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)

**Newsletter services**

Newsletter signup is off by default. If you enable **Show newsletter signup** and configure a page from a service such as Hostinger Reach, Omnisend or Mailchimp, your static site links to or displays that signup page. Visitors connect to the selected service when they open the link or view the signup page within your site. That service handles subscriptions, mail delivery and any information visitors submit.

= Support =

See the [usage guide](https://github.com/zeropress-app/zeropress-static#readme) for setup instructions and settings.

Questions, bug reports and feature requests are welcome through:

* [GitHub Issues](https://github.com/zeropress-app/zeropress-static/issues): Our preferred channel.
* [WordPress.org support forum](https://wordpress.org/support/plugin/zeropress-static/).
* Email: Use the address listed on the [ZeroPress GitHub profile](https://github.com/zeropress-app). No special format is required.

Write in the language you are most comfortable with. You do not need to write in English; we will reply in the same language.

Please remove passwords, API tokens and personal data from your message, logs and attachments. For bug reports, include your plugin, WordPress and PHP versions and steps to reproduce, if available.

Report security vulnerabilities through [private vulnerability reporting](https://github.com/zeropress-app/zeropress-static/security/advisories/new).

== Installation ==

= Automatic installation =

Install ZeroPress Static from the WordPress Plugin Directory:

1. In your WordPress admin, open **Plugins → Add New Plugin**.
2. Search for **ZeroPress Static**.
3. On the ZeroPress Static result, select **Install Now**, then **Activate**.
4. Open **ZeroPress → Settings** to configure the plugin.

= Manual installation =

1. Download the installation ZIP from the [GitHub releases](https://github.com/zeropress-app/zeropress-static/releases).
2. In your WordPress admin, open **Plugins → Add New Plugin → Upload Plugin**.
3. Select the downloaded ZIP, choose **Install Now**, then **Activate Plugin**.
4. Open **ZeroPress → Settings** to configure the plugin.

= First publication with GitHub and Cloudflare =

We recommend GitHub publishing with Cloudflare hosting for your first site. This setup connects publication to automatic builds and deployments, so you can update your site from WordPress. Create an account with each service before starting. Cloudflare provides a site address, so you can start without a custom domain.

1. **Deploy the sample site.** Open [ZeroPress WXR Starter](https://github.com/zeropress-app/zeropress-starter-wxr) and select **Deploy to Cloudflare**. Connect your GitHub account, choose **Create private Git repository**, and keep the Starter's default build and deployment settings. When deployment finishes, open the site address provided by Cloudflare and confirm that the sample site appears.
2. **Return to WordPress.** After the sample deployment, continue here with the plugin; it prepares your content export for you. Open **ZeroPress → Settings → Site**, enter the new site's address under **Static site URL**, and select **Save site settings**. Leave the other settings at their defaults for this first publication.
3. **Connect your repository.** Under **GitHub publishing**, enter the URL of the repository created in step 1. Set **Publishing branch** to the branch Cloudflare builds for the live site, usually `main`, and select **Save publishing target**.
4. **Register a GitHub token.** Select **Create a GitHub token**. Choose **Only select repositories**, select your publishing repository, and grant **Contents: Read and write**. Copy the generated token back to WordPress, select **Register token**, then **Test connection**. Continue when the test succeeds.
5. **Publish your content.** Make sure WordPress has at least one public, published post or page. Open **ZeroPress → Publish**, select **Prepare publishing data**, and review the content counts and destination. Select **Publish to GitHub** and confirm.
6. **Check your live site.** GitHub publication updates the repository; Cloudflare then builds and deploys the site. In Cloudflare, open your project under **Workers & Pages** and wait for the new build and deployment to succeed. Open or refresh the site address from step 1 and check that your own posts and images appear in place of the sample content.

After checking the site, enable **Allow search engine crawling** under **Site** if you want search engines to crawl it. This option starts off. Save the site settings, then prepare and publish again. You can configure menus, comments, newsletters and media URL replacements later as needed.

= Publish from the editor =

With GitHub publishing configured, administrators can select **GitHub** beside **Update** or **Publish** in the classic editor. In the block editor, select the cloud upload icon beside **Save** or **Publish** (tooltip: **Open GitHub publishing**), or choose **ZeroPress publish** from the **⋮** options menu. The menu entry is also available on small screens, where the toolbar icon is hidden.

Save changes in WordPress, prepare publishing data in the dialog, review the result, then select **Publish to GitHub**. This publishes all saved public posts and pages. Unsaved changes block preparation and publication.

When a static site URL is configured, the dialog links to the current public post or page on that site before and after publishing. It opens the deployed page, so changes appear there after the site's build and deployment finish.

== Frequently Asked Questions ==

= Is ZeroPress Static really free? =

Yes. ZeroPress Static is free and open source. All features are included, with no paid plans, subscriptions or hidden fees. You do not need a ZeroPress account.

Our recommended setup uses your own GitHub and Cloudflare accounts. Both services offer free plans with usage limits. Paid options, WordPress hosting, custom domains and newsletter services may have separate costs under each provider's pricing and terms.

= Does the plugin send data to ZeroPress? =

The plugin does not send your site's content or usage statistics to ZeroPress.

When you use GitHub publishing or enable optional integrations, data is exchanged with those services as described in the External services section.

= What is the recommended setup for my first site? =

We recommend GitHub publishing with Cloudflare hosting. GitHub stores the content and settings you publish from WordPress, and Cloudflare automatically builds and deploys updates from the connected repository. Follow **First publication with GitHub and Cloudflare** in the installation instructions.

File downloads are available for custom build and hosting workflows.

= Is a static site URL required? =

We recommend setting it to your deployed site's address under **Settings → Site**. It is required for comment writing and RSS, and helps search engines identify the addresses of your pages.

= Can WordPress and the static site use different addresses? =

Yes. Enter the address visitors will use under **Settings → Site → Static site URL**. Published post and page links, and menu links that point to your WordPress site, will use this address.

Links you added inside posts and pages keep their saved addresses, so review them if they should point to the static site. Media addresses can be changed separately under **Media URL replacements**. Comments still come from WordPress.

= What does Allow search engine crawling do? =

This tells search engines whether they may crawl your static site. It is off by default. Enable it when your site is ready, save the site settings, then prepare and publish again.

It does not block visitors from accessing the site. If you need a private site, use your hosting provider's access controls.

= Which content is exported? =

Published posts and pages without passwords are included, along with their categories, tags, author display names, selected classic menus and media information. Drafts, private or scheduled content, user login names and user email addresses are excluded. Comments stay in WordPress and can be displayed on the static site when enabled.

The plugin publishes saved content. It does not recreate page-builder layouts, shortcodes or blocks that need WordPress to generate their output. Content types beyond standard posts and pages, and menus created with the Navigation block, are not supported.

= Can I restrict public REST API access? =

Yes. Enable **Restrict public REST API access** under **Settings → API protection** to keep only the APIs needed for ZeroPress Static comments publicly accessible.

This option is off by default and takes effect when you save. Other plugins that use the public REST API may be affected.

[Learn more about API protection](https://github.com/zeropress-app/zeropress-static#api-protection).

= How do menus work? =

In **Settings → Menus**, choose classic WordPress menus for your static site, such as its main menu and footer. Create and edit them in WordPress's classic menu editor. Only menus selected in ZeroPress are published; your WordPress theme's menu assignments are kept separate. Menus created with the Navigation block are not supported.

= How are media URLs and the Site Icon handled? =

Images and other media stay at their existing locations; the plugin does not copy the files to your static site. If your media is available at another address, use **Settings → Media URL replacements** to update the addresses used by the static site. The replacement addresses must make those files available to visitors.

Your WordPress Site Icon is used as the site's browser and device icon. It follows the same media address settings. If no Site Icon is set, the static site's default icons are used.

= Can visitors read comments without writing them? =

Yes. Under **Settings → Comments**, enable comment display and leave comment writing off. Visitors can read existing comments without a comment form. Both options are off by default. Save your settings, then prepare and publish again.

If you enable writing later, enter your static site URL first. Comment writing uses built-in bot protection by default, or you can choose Cloudflare Turnstile. Your WordPress comment settings still apply.

[Learn more about comment settings](https://github.com/zeropress-app/zeropress-static#comments).

= Does the plugin send newsletters? =

No. It connects your static site to a signup page you create with a newsletter service. Visitors can open that page from a link or view it within your site if the service allows it. The newsletter service manages subscriptions and sends the emails.

= Can I publish again without changing content? =

Yes. To run your connected build and deployment again without editing content, open **ZeroPress → Publish**, expand **Republish options**, and select **Publish to GitHub again**. This option is available when there are no changes to publish. Your content, post dates and site settings stay unchanged.

= What should I do if publication needs verification? =

**Publication needs verification** means the plugin could not confirm whether GitHub received the update. Check the latest update on your GitHub publishing branch before preparing or publishing again. If you use Cloudflare, also check your project's deployment status. If you are unsure, contact support before retrying.

= How are credentials and settings stored? =

Your settings are stored in WordPress, with saved GitHub tokens and Turnstile Secret Keys encrypted. Deactivating the plugin keeps this data. Deleting the plugin through WordPress removes it.

== Screenshots ==

1. Prepare publishing data, review the result, and publish to GitHub.
2. Settings navigation and site information from WordPress.
3. Control WordPress comment display, writing, and bot protection.
4. Configure newsletter connection, display and credits.

== Changelog ==

= 1.0.0 =

* Initial release: publish WordPress posts, pages and selected classic menus with ZeroPress WXR Starter.
* Publish to GitHub with progress feedback and checks for content changes; downloads are available for custom setups.
* Prepare and publish from classic and block editors, with a check for unsaved changes and links to posts on the static site.
* Use the static site address for post, page and menu links, with separate address settings for media and the WordPress Site Icon.
* Configure search, RSS, archives, footer credits and newsletter signup pages.
* Display WordPress comments and optionally allow writing, with built-in bot protection or Cloudflare Turnstile.
* Optionally limit public access to WordPress APIs while keeping ZeroPress Static comments available.
* Provide English, Korean, Japanese, Spanish (Spain), German, French and Portuguese (Brazil) admin screens.
