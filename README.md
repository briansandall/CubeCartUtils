# cc_image_importer
Image import script for CubeCart

Simple importer script to add database entries for pre-uploaded images, rather
than adding them each individually from CubeCart's admin interface. Optionally
adds image:product and/or image:matrix relationships for each image processed.

INSTALLATION

1. Edit the variables under USER SETTINGS in the cc_image_importer.php file

2. Upload the modified version of this script to any accessible path on your website, e.g. yoursite.com/utils/

3. Ready to use:
	- Navigate to the script URL, e.g. https://www.yoursite.com/utils/cc_image_importer.php
	- Make sure JavaScript is enabled
	- Usage instructions are on the page, read carefully and use the 'Dry-Run' button

IMPORTANT SECURITY WARNING

There is no authentication mechanism built in to this script, so while it is on your
web host, ANYONE can use it. As such, it is up to you to prevent unauthorized access.

The following steps are recommended to help prevent unauthorized access:

- Place utility scripts in a sub directory of your web root, rather than the root folder.

- Place an .htaccess file in your utility script directory with the following directives:
	
	- deny from all
	
	- allow from 0.0.0.0 (replace '0.0.0.0' with your actual IP address)

- If the script requires uploading any additional files, upload those to a separate
  directory with an .htaccess file containing only 'deny from all'

- Use obscure directory names such as '2350ALD799G' instead of 'utils' and consider
  changing it each time you use the script

- Delete the script(s) from your server as soon as you are finished using them

- Consider password protecting the directory in which the script(s) reside:
	- https://www.webhostinghero.com/password-protect-folder-website/
	- http://www.thesitewizard.com/apache/password-protect-directory.shtml
	- https://perishablepress.com/stupid-htaccess-tricks/#security
