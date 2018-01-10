## WeirdGloopMessages
This extension handles site-wide interface messages on Weird Gloop wikis. It is a fork of Wikimedia's WikimediaMessages.

### Rights
* `editinterfacesite` - allows the user to edit site-wide interface messages if `$wglProtectSiteInterface` is enabled

### Configuration settings
* `$wglProtectSiteInterface` - indicates if site-wide interface messages should be protected from local edits. This should probably be set to `true` so that links to policies and legal compliance stuff can't be overwritten by local admins