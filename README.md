# CubeCart Utility Script Collection
This is a collection of utility scripts for use with CubeCart v6.0.8 or higher.

Current scripts include:

- Image Importer: Quickly and easily import pre-uploaded images into CubeCart's database and automatically associate them with products.

- Price Updater: Update prices for your entire store in 5 minutes or less

## DISCLAIMER

Each script is provided  "as is" without warranty of any kind, either express or implied,
including without limitation any implied warranties of condition, uninterrupted use,
merchantability, fitness for a particular purpose, or non-infringement.

## INSTALLATION

Be sure to read any README file located in the script's directory for specific instructions.

1. Edit the variables under USER SETTINGS in the script file

2. Upload the modified version of the script to any accessible path on your website, e.g. yoursite.com/utils/

3. Ready to use:
	- Navigate to the script URL, e.g. https://www.yoursite.com/utils/script.php
	- Make sure JavaScript is enabled
	- Usage instructions are on the page, read carefully and use the 'Dry-Run' button

## IMPORTANT SECURITY WARNING

There is no authentication mechanism built in to any of these scripts, so while it is on your web host, ANYONE can use it. As such, it is up to you to prevent unauthorized access.

My recommendation for reasons of both security and store stability is to use the scripts on a local installation of your site, i.e. NOT live, and then upload the database changes to your live site once you've made sure everything is still working correctly.

### Using the Scripts Locally

During step 2 of installation, DO NOT upload the scripts to your live site; instead, place them in your local web site directory, e.g. C:\wamp\www\git\cubecart\utils\scripts_go_here.

After using the scripts and making changes to your local database, export it and upload/import it to your live site; I use the following code in a .bat file to generate the SQL output needed to keep all of my product data in sync:

    "C:\wamp\bin\mysql\mysql5.7.9\bin\mysqldump.exe" -uroot -p cubecart CubeCart_documents CubeCart_category CubeCart_category_index CubeCart_filemanager CubeCart_image_index CubeCart_inventory CubeCart_manufacturers CubeCart_option_assign CubeCart_option_group CubeCart_option_matrix CubeCart_option_value CubeCart_seo_urls > C:\wamp\www\cubecart\cc_update.sql

You need to check to make sure the path to mysqldump.exe is correct and can change the output destination to whatever makes sense for you.

That outputs an SQL file with all the code necessary to update your live database with changes made locally using any of these utility scripts.

NOTE: If you are using a case-insensitive file system like Windows for your local test server, be sure to find and replace all instances of 'cubecart_' with 'CubeCart_' in the SQL output, as well as fixing your custom prefix if applicable.

If you added images, be sure to also upload them to the live site.

To update your live store, simply run the SQL on your live store and you're all set!

### Using the Scripts Live

The following steps are recommended to help prevent unauthorized access **if you decide to upload the utility scripts to your live site**:

- Place utility scripts in a sub directory of your web root, rather than the root folder

- Place an .htaccess file in your utility script directory with the following directives (an example .htaccess file can be found in this repository):
	
	- deny from all
	
	- allow from 0.0.0.0 (replace '0.0.0.0' with your actual IP address)

- If the script requires uploading any additional files, upload those to a sub-directory of the utility script folder and add an .htaccess file containing only 'deny from all' to that directory

- Use obscure directory names such as '2350ALD799G' instead of 'utils' and consider
  changing it each time you use the script

- Delete the script(s) from your server as soon as you are finished using them

- Consider password protecting the directory in which the script(s) reside:
	- https://www.webhostinghero.com/password-protect-folder-website/
	- http://www.thesitewizard.com/apache/password-protect-directory.shtml
	- https://perishablepress.com/stupid-htaccess-tricks/#security
