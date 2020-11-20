## GloopTweaks
This extension handles site-wide interface messages, tweaks, and improvements on Weird Gloop wikis. It is a fork of Wikimedia's WikimediaMessages with increased functionality, handling server-farm wide modifications to the core MediaWiki software.

### Rights
* `editinterfacesite` - Allows the user to edit site-wide interface messages if `$wglProtectSiteInterface` is enabled

### Configuration settings
* `$wglProtectSiteInterface` - Protect site-wide interface messages from local edits
* `$wglSendDetailsWithContactPage` - Send additional info with Special:Contact messages (e.g IP and User-Agent)
* `$wglAddFooterLinks` - Add a link to our Terms of Use etc to the footer
* `$wglEnableMessageOverrides` - Enable overriding certain MediaWiki messages with our own
* `$wglRequireLicensesToUpload` - Enforces requiring MediaWiki:Licenses to not be blank for uploads to be enabled
* `$wglEnableSearchboxMetadata` - Add structured data to the main page to add a Google Sitelinks search box
* `$wglEnableLoadingDarkmode` - Enable loading the wg.darkmode ResourceLoader module when the darkmode cookie is true
* `$wglEnableLoadingReadermode` - Enable loading the wg.readermode ResourceLoader module when the readermode cookie is true