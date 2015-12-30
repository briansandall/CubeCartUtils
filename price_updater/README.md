# CubeCart Price Updater

This script is part of the [CubeCartUtils](https://github.com/briansandall/CubeCartUtils) collection.

A script to update product pricing from a CSV file. Note that the CSV file needs to be uploaded to your server (usually by FTP) prior to using this script.

FEATURES

- Update prices for your entire store in 5 minutes or less!

- Supports updating both 'price' and 'sale_price'

- Supports using the product manufacturer in addition to the product code to avoid errors if two or more products from different manufacturers happen to have the same product code

- Support for matrix-based product pricing [1]

- Option to automatically disable product matrix combinations not found in the price list [2]

- Allows performing a 'Dry Run' first to see expected results of the operation without actually making any changes to your files or database

It is recommended (though not strictly necessary) to break up large CSV files into smaller files and to run the script for each individually. This makes it easier for you to discover possible issues when reviewing the 'Dry Run' results.

[1] Provided your CubeCart installation supports this capability; at the very least, the 'CubeCart_option_matrix' table must have both a 'price' and 'sale_price' column of type 'decimal(16,2) unsigned null'

[2] Provided your CubeCart installation supports this capability; at the very least, the 'CubeCart_option_matrix' table must have the 'set_enabled' column of type 'tinyint(1) unsigned not null'

SECURITY ADVISORY
There is no authentication mechanism built in to this script, so while it is on your web host, ANYONE can use it. As such, it is up to you to prevent unauthorized access.

Please see the README file in the CubeCartUtils repository for more information.