# CubeCart Image Importer

This script is part of the [CubeCartUtils](https://github.com/briansandall/CubeCartUtils) collection.

A simple importer script to quickly add database entries for pre-uploaded (usually via FTP) images, rather than uploading images individually from CubeCart's admin interface.

Depending on how many images you have, this can save you hours upon hours of precious time.

You can save even more time by naming your image files using the product code for the product to which they belong and then using the 'Add Image Relationships' options.

FEATURES

- Imports one image at a time or entire directories (including sub-directories!)

- Super fast! Import hundreds or even thousands of images in mere seconds, all of which are then available from CubeCart's normal image selection interfaces

- Files with existing database entries are safely ignored

- Options to add image:product and/or image:matrix relationships for each processed image; uses filename to product code comparison to determine matches

- Associate multiple images to a product by naming them 'product_code-1', 'product_code-2', etc.

- Support for advanced REGEXP to associate files with products or matrix entries

- Allows performing a 'Dry Run' first to see expected results of the operation without actually making any changes to your files or database

DETAILS

An image is considered to match a product when the filename (not including file extensions) is identical to the product code.

Multiple image relationships can be created by naming your files e.g. 'code-1.jpg', 'code_2.gif', etc., using either a hyphen, underscore, or neither before a number. You may optionally define your own variant naming convention, e.g. '-var' would match 'ABC123-var0', 'ABC123-var-1', and 'ABC123-var_2' to the product code 'ABC123', but would not match 'ABC1234' or 'ABC123-1'

If the filename is an exact match for a matrix entry's product code, that will take precedence over adding the file as an additional image for the main product.

Note that this works best when the product(s) with all related options and matrix entries have already been added.

Note also that the default CubeCart skin does not support displaying individual matrix images, and custom skins may handle them differently. As such, updating the 'option_matrix.image' may not have any observable effect in your store.

SECURITY ADVISORY
There is no authentication mechanism built in to this script, so while it is on your web host, ANYONE can use it. As such, it is up to you to prevent unauthorized access.

Please see the README file in the CubeCartUtils repository for more information.