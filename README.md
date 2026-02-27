## YetiTweaks
This extension handles site-wide interface messages, tweaks, and improvements on Yeti Wikis. It is a fork of Weird Gloop's GloopTweaks with increased functionality, handling server-farm wide modifications to the core MediaWiki software.

### Rights
* `editinterfacesite` - Allows the user to edit site-wide interface messages if `$wgYetiTweaksProtectSiteInterface` is enabled

### Configuration settings
* `$wgYetiTweaksAddFooterLinks` - Add a link to our Terms of Use etc to the footer
* `$wgYetiTweaksEnableMessageOverrides` - Enable overriding certain MediaWiki messages with our own
* `$wgYetiTweaksEnableTheming` - Enable loading themes when the theme cookie is set or the legacy darkmode cookie is true
* `$wgYetiTweaksEnableLoadingFixedWidth` - Enable loading the wg.fixedwidth ResourceLoader module when the readermode cookie is true
