# cc_image_importer
Image import script for CubeCart

Simple importer script to add database entries for pre-uploaded images, rather
than adding them each individually from CubeCart's admin interface. Optionally
adds image:product and/or image:matrix relationships for each image processed.

INSTALLATION

1. Edit the variables under USER SETTINGS in the cc_image_importer.php file

2. Upload the modified version of this file to your website's root public directory

3. Ready to use:
	- Navigate to http://www.yoursite.com/cc_image_importer.php
	- Make sure JavaScript is enabled
	- Usage instructions are on the page, read carefully and use the 'Dry-Run' button

IMPORTANT SECURITY NOTICE

There is no authentication mechanism built in to this script, so while it is on your
site, ANYONE can use it.

Therefore it is recommended to remove the script immediately after you are finished
using it to prevent unauthorized users from tampering with your site.
