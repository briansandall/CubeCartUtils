<?php
/*
Script to automatically update prices based on product code.
Product and matrix entries that match will have their pricing updated.
Once the script is finished, products with modified matrix entries will
update their pricing based on the least expensive matrix option, as this
is the initial price that gets displayed in the catalog.

INSTRUCTIONS / INSTALLATION
1. Edit the variables under USER SETTINGS below
2. Upload the modified version of this script to any accessible path on your website, e.g. yoursite.com/utils/
3. Upload a CSV-formatted price list to the 'PATH' directory on your server (see user settings, below)
4. Ready to use:
	- Navigate to the script URL, e.g. https://www.yoursite.com/utils/cc_price_updater.php
	- Make sure JavaScript is enabled
	- Usage instructions are on the page, read carefully and use the 'Dry-Run' button

IMPORTANT SECURITY WARNING
There is no authentication mechanism built in to this script, so while it is on your
web host, ANYONE can use it. As such, it is up to you to prevent unauthorized access.

Please see the README file in the CubeCartUtils repository for more information.
*/
//================== USER SETTINGS ===================//

// Database credentials
DEFINE('DB_HOST', 'localhost');
DEFINE('DB_USER', 'username');
DEFINE('DB_PASS', 'password');
DEFINE('DB_NAME', 'example_db');
DEFINE('TABLE_PREFIX', ''); // enter the same prefix you used, if any, when setting up the database

// Change to true if your `cubecart_option_matrix` table supports enabling
// and disabling entries individually via the `set_enabled` column.
// See: https://github.com/briansandall/v6/commit/b33bbca1ee0be06d5ae4b5e9b7cb9c22f42a269a
DEFINE('MATRIX_STATUS_ON', false);

// Change to false if your database tables do not support transactions
// (e.g. they do not use the InnoDB storage engine)
DEFINE('USE_TRANSACTIONS', true);

// Path to the public folder on your web server where price lists are stored
// NOTE that you should put an .htacess file in this folder with the 'deny from all'
// directive to prevent others from viewing or downloading your files.
DEFINE('PATH', '/home/yourname/public_html/prices/'); // make sure it ends with a '/'!!!

//====================================================//
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title>Price Updater</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width" />
	<meta name="title" content="Price Updater" />
	<meta name="description" content="Update product prices from a CSV file" /> 
	<meta name="author" content="Brian Sandall" /> 
</head>
<?php
$errors = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$file = trim(filter_input(INPUT_POST, 'file'));
	if (!empty($file) && is_string($file)) {
		if (strpos($file, '..') !== false) {
			$errors['file'] = '* Invalid file path';
		} else {
			$file = ltrim(rtrim($file, '\/'), '\/');
			$filename = PATH . $file;
			if (!is_file($filename)) {
				$errors['file'] = '* Invalid file path or file name';
			} elseif (getMimeType($filename) !== 'text/plain') {
				$errors['file'] = '* Invalid file type: ' . getMimeType($filename);
			}
		}
	} else {
		$errors['file'] = '* Required field';
	}
	$required = array('product_code'=>true,'list_price'=>true,'sale_price'=>true);
	$header_labels = filter_input(INPUT_POST, 'header_labels', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (!is_array($header_labels)) {
		$errors['header_labels']['missing'] = '* Please enter valid header labels';
	} else {
		foreach ($header_labels as $k => $v) {
			if (empty($v) && !empty($required[$k])) {
				$errors['header_labels'][$k] = '* Required field';
			} elseif (!preg_match('/^[a-z0-9-_\040]+$/i', $v)) {
				$errors['header_labels'][$k] = '* Only letters, digits, spaces, hyphens, and underscores are allowed';
			}
		}
	}
	// TODO allow script to parse temporarily uploaded file?
	// TODO input delimiter
	$options = array(
		/** true to perform a dry-run that doesn't change the database */
		'dry_run'            => isset($_POST['btn_dry']),
		/** header labels expected in the CSV file */
		'header_labels'      => array(
				'product_code' => array('label'=>$header_labels['product_code'], 'required'=>true),
				'list_price'   => array('label'=>$header_labels['list_price'], 'required'=>true),
				'cost_price'   => array('label'=>$header_labels['cost_price'], 'required'=>false),
				'sale_price'   => array('label'=>$header_labels['sale_price'], 'required'=>true),
				'manufacturer' => array('label'=>$header_labels['manufacturer'], 'required'=>false),
				'upc'          => array('label'=>$header_labels['upc'], 'required'=>false),
			),
		/** true to update the main product's date modified field if the product's pricing changes */
		'update_date'        => isset($_POST['update_date']),
		/** true to update the date modified field for any product found on the price list, irrespective of price changes */
		'update_date_all'    => isset($_POST['update_date_all']),
		/** allow 'sale' prices to be greater than the list price */
		'allow_upsell'       => isset($_POST['allow_upsell']),
		/** sets status of products / matrix entries that have a price update to enabled */
		'enable_updated'     => isset($_POST['enable_updated']),
		/** true to update prices in the option matrix - only select if your database supports this! */
		'update_matrix'      => isset($_POST['update_matrix']),
		/** update the main product price with the lowest non-zero matrix price */
		'update_main_price'  => isset($_POST['update_main_price']),
		/** true to disable product and matrix codes that exist in the database but weren't found on the price list */
		'disable_products'   => isset($_POST['disable_products']),
		/** true to perform a dry run on price updates in order to check for and disable missing products */
		'disable_only'       => isset($_POST['btn_disable']),
		/** true to disable warnings for products found on the price list but not in the database */
		'ignore_missing'     => isset($_POST['ignore_missing']),
		/** true to check for and update product UPC codes */
		'upc_update'         => isset($_POST['upc_update']),
		/** true to overwrite any existing UPC codes with those on the price list */
		'upc_overwrite'      => isset($_POST['upc_overwrite']),
		// TODO option to save warnings to disk
	);
	if (!$options['update_matrix'] && ($options['update_main_price'] || $options['disable_products'])) {
		$errors['matrix_options'] = 'You must check <strong>Update price entries in the option matrix</strong> in order to use any of the other Matrix Options.';
	}
	if ($options['update_matrix'] && !MATRIX_STATUS_ON) {
		$errors['matrix_options'] = 'You must enable MATRIX_STATUS_ON in the script file in order to use any of the <strong>Matrix Options<strong>.';
	}
	$options['manufacturer'] = filter_input(INPUT_POST, 'manufacturer');
	if (empty($options['manufacturer'])) {
		$errors['manufacturer'] = '* Required field';
	} elseif (!preg_match('/^[a-z0-9-_\040&]+$/i', $options['manufacturer'])) {
		$errors['manufacturer'] = '* Only letters, digits, spaces, hyphens, underscores, and the ampersand (&) are allowed';
	}
	if (empty($errors)) {
		$dbc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
		if (mysqli_connect_errno()) {
			die("Could not connect to MySQL: " . mysqli_connect_error());
		}
		$result = array('updated'=>array(), 'failed'=>array(), 'not_found'=>array(), 'warning'=>array(), 'disabled'=>array());
		if (USE_TRANSACTIONS) {
			$dbc->autocommit(false);
			try {
				$result = updatePrices($dbc, $filename, $options);
				// Rollback any changes that may have occurred during dry run (no changes should have been made, but just in case)
				if (!empty($options['dry_run'])) {
					throw new \Exception("Dry run completed successfully");
				}
			} catch (\Exception $e) {
				$result['failed'][] = "Rolling back changes due to exception: " . htmlspecialchars($e->getMessage());
				if (!$dbc->rollback()) {
					$result['failed'][] = "CRITICAL: Failed to rollback database!";
				}
			} finally {
				if (!$dbc->autocommit(true)) {
					$result['failed'][] = "Failed to commit database changes: $dbc->errno - $dbc->error";
				}
			}
		} else {
			try {
				$result = updatePrices($dbc, $filename, $options);
			} catch (\Exception $e) {
				$result['failed'][] = "Exception while updating prices: " . htmlspecialchars($e->getMessage());
			}
		}
		$dbc->close();
		// Leave information on screen instead of redirecting to self
		// TODO log results instead of or in addition to displaying on screen
	}
}
$directories = getDirectories(PATH, true);
?>
<style>
	#content { float:left; width:67%; margin-bottom:40px;}
	#donate { float:left; width:33%; text-align:center;}
	#sidebar { float:left; width:33%; margin-bottom:40px;}
	.fleft { float:left;}
	.fright { float:right;}
	.clear { clear:both;}
	.toggle { display:none;}
	.wide { width: 80%;}
	.half { width: 50%;}
	.light-border { border: 1px solid black; margin-bottom:20px;}
	.light-border > * { margin-left: 20px; margin-right: 20px;}
	.inner { margin: 20px;}
	ol > li { padding-bottom: 5px;}
	ul.navigable_tree { margin:0; list-style:none; float:left;}
	li > ul { padding-left:30px; list-style:none;}
	ul.navigable_tree, .tree_header { padding-left:40px;}
	li.branch, li.branch > a { color:blue; }
	li.leaf, li.leaf > a { color:orange; text-decoration:none;}
	.alert { color: #ff0000;}
	input, label { margin-right: 20px;}
	input[type=checkbox] { float:left;}
	input.error { border: 2px solid #800517;}
	span.error { color: #800517; font-weight: bold;}
	span.toggle_link { padding-left:20px;}
</style>
<body>
	<div id="content">
		<h1>CubeCart Price Updater</h1>
		<p>This script automatically updates your products' list and sale prices by reading a CSV-formatted text file.</p>
		<p>If your store's database supports prices at the option matrix level, those may also be updated by checking the appropriate option.</p>
		<h4>INSTRUCTIONS<span class="toggle_link">[<a id="instructions-toggle" href="#instructions-toggle" onclick="toggle('instructions')">Show</a>]</span></h4>
		<div class="toggle" id="instructions">
			<div class="light-border">
				<ol>
					<li>Upload* an .htaccess file with the <em>Deny From All</em> directive to <strong><?php echo htmlspecialchars(PATH); ?></strong> on your server</li>
					<li>Upload* a price list in CSV format to your server's <strong><?php echo htmlspecialchars(PATH); ?></strong> directory or a subdirectory thereof</li>
					<li>Upload* this script to an accessible folder on your server, e.g. your_site/utils</li>
					<li>Navigate to the script URL, e.g. <a href="#">https://your_site/utils/cc_price_updater.php</a></li>
					<li><strong>BACK UP YOUR DATABASE</strong>. It only takes a minute, and could save you hours or more.</li>
					<li>Select a price list from the <strong>Directory Listing</strong> to the right, choose any options, and click <strong>Dry Run</strong></li>
					<li>Review the results - if it looks good, click <strong>Submit</strong> to make the changes for real</li>
				</ol>
			</div>
		</div>
		<p>* Please see the README for important security information.</p>
		<form method="post" action="#" accept-charset="utf-8">
			<h4>IMPORTANT: <span class="alert">Before using this script, BACKUP YOUR DATABASE!</span></h4>
			<p class="alert">It only takes a minute, and could save you hours or more should anything go wrong.<br>Note that only the `cubecart_inventory` and `cubecart_option_matrix` tables are modified by this script.</p>
			<label for="file"><strong>Select a Price List</strong></label><br>
			<small>Enter a folder path and filename, or use the 'Directory Listing' tree to the right to select one.</small><br>
			<small>Paths are relative to: <em><?php echo PATH; ?></em></small><br>
			<input type="text" id="file" name="file" class="wide<?php echo (empty($errors['file']) ? '' : ' error'); ?>" <?php echo (isset($file) ? ' value="' . htmlspecialchars($file) . '"' : ''); ?> />
			<?php echo (empty($errors['file']) ? '' : '<br><span class="error">' . $errors['file'] . '</span>'); ?>
			<br><br>
			<label for="manufacturer"><strong>Default Manufacturer</strong></label><br>
			<small>Required to resolve any product code collisions</small><br>
			<input type="text" id="manufacturer" name="manufacturer"<?php echo (empty($errors['manufacturer']) ? '' : ' class="error"'); ?><?php echo (isset($options['manufacturer']) ? ' value="' . htmlspecialchars($options['manufacturer']) . '"' : ''); ?> />
			<?php echo (empty($errors['manufacturer']) ? '' : '<br><span class="error">' . $errors['manufacturer'] . '</span>'); ?>
			<br>
			<h4>OPTIONS</h4>
			<p>CSV Headers: Define custom labels for retrieving values from the CSV file.</p>
			<?php echo (empty($errors['header_labels']['missing']) ? '' : '<span class="error">' . $errors['header_labels']['missing'] . '</span><br><br>'); ?>
			<div class="fleft half">
				<label for="header_product_code" title="Required to match product and/or option matrix entries"><strong>Product Code Header Label</strong></label><br>
				<input type="text" id="header_product_code" name="header_labels[product_code]"<?php echo (empty($errors['header_labels']['product_code']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['product_code']) ? htmlspecialchars($header_labels['product_code']) . '"' : 'Product Code'); ?>" required="required" title="Required to match product and/or option matrix entries" />
				<?php echo (empty($errors['header_labels']['product_code']) ? '' : '<br><span class="error">' . $errors['header_labels']['product_code'] . '</span>'); ?>
			</div><div class="fleft half">
				<label for="header_manufacturer" title="If present in the CSV file, this field will be used to resolve conflicting product codes instead of the default manufacturer"><strong>Manufacturer Header Label</strong> (optional)</label><br>
				<input type="text" id="header_product_code" name="header_labels[manufacturer]"<?php echo (empty($errors['header_labels']['manufacturer']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['manufacturer']) ? htmlspecialchars($header_labels['manufacturer']) . '"' : 'Manufacturer'); ?>" title="If present in the CSV file, this field will be used to resolve conflicting product codes instead of the default manufacturer" />
				<?php echo (empty($errors['header_labels']['manufacturer']) ? '' : '<br><span class="error">' . $errors['header_labels']['manufacturer'] . '</span>'); ?>
			</div><div class="clear"></div>
			<br>
			<div class="fleft half">
				<label for="header_list_price" title="List price for the product"><strong>List Price Header Label</strong></label><br>
				<input type="text" id="header_list_price" name="header_labels[list_price]"<?php echo (empty($errors['header_labels']['list_price']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['list_price']) ? htmlspecialchars($header_labels['list_price']) . '"' : 'List Price'); ?>" required="required" title="List price for the product" />
				<?php echo (empty($errors['header_labels']['list_price']) ? '' : '<br><span class="error">' . $errors['header_labels']['list_price'] . '</span>'); ?>
			</div><div class="fleft half">
				<label for="header_cost_price" title="Price for which the product will actually be sold, i.e. the 'Sale Price' - should not be greater than the List Price"><strong>Cost Price Header Label</strong></label><br>
				<input type="text" id="header_cost_price" name="header_labels[cost_price]"<?php echo (empty($errors['header_labels']['cost_price']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['cost_price']) ? htmlspecialchars($header_labels['cost_price']) . '"' : 'Cost'); ?>" title="Price at which your company can procure the product" />
				<?php echo (empty($errors['header_labels']['cost_price']) ? '' : '<br><span class="error">' . $errors['header_labels']['cost_price'] . '</span>'); ?>
			</div><div class="clear"></div>
			<br>
			<div class="fleft half">
				<label for="header_sale_price" title="Price for which the product will actually be sold, i.e. the 'Sale Price' - should not be greater than the List Price"><strong>Sale Price Header Label</strong></label><br>
				<input type="text" id="header_sale_price" name="header_labels[sale_price]"<?php echo (empty($errors['header_labels']['sale_price']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['sale_price']) ? htmlspecialchars($header_labels['sale_price']) . '"' : 'Price'); ?>" required="required" title="Price for which the product will actually be sold, i.e. the 'Sale Price' - should not be greater than the List Price" />
				<?php echo (empty($errors['header_labels']['sale_price']) ? '' : '<br><span class="error">' . $errors['header_labels']['sale_price'] . '</span>'); ?>
			</div><div class="fleft half">
				<label for="header_upc" title="UPC column header"><strong>UPC Header Label</strong> (optional)</label><br>
				<input type="text" id="header_upc" name="header_labels[upc]"<?php echo (empty($errors['header_labels']['upc']) ? '' : ' class="error"'); ?> value="<?php echo (isset($header_labels['upc']) ? htmlspecialchars($header_labels['upc']) . '"' : 'UPC'); ?>" title="UPC column header" />
				<?php echo (empty($errors['header_labels']['upc']) ? '' : '<br><span class="error">' . $errors['header_labels']['upc'] . '</span>'); ?>
			</div><div class="clear"></div>
			<p>Other Options</p>
			<input type="checkbox" id="update_date" name="update_date"<?php echo (isset($options['update_date']) && !$options['update_date'] ? '' : ' checked="checked"'); ?> />
			<label for="update_date" class="fleft">Update the modification date for products whose prices change</label>
			<div class="clear"></div><br>
			<input type="checkbox" id="update_date_all" name="update_date_all"<?php echo (!empty($options['update_date_all']) ? ' checked="checked"' : ''); ?> />
			<label for="update_date_all" class="fleft">Update the modification date for any product found on the price list, whether or not its price(s) changed</label>
			<div class="clear"></div><br>
			<input type="checkbox" id="enable_updated" name="enable_updated"<?php echo (!empty($options['enable_updated']) ? ' checked="checked"' : ''); ?> />
			<label for="enable_updated" class="fleft">Automatically enable products (and matrix entries, if applicable) whose prices are updated</label>
			<div class="clear"></div><br>
			<input type="checkbox" id="allow_upsell" name="allow_upsell"<?php echo (!empty($options['allow_upsell']) ? ' checked="checked"' : ''); ?> />
			<label for="allow_upsell" class="fleft">Allow 'sale' prices to be greater than the list price*</label>
			<br><small>* If such is the case, the 'sale' price will be used as the list price, and the item will not be considered 'on sale'</small>
			<div class="clear"></div><br>
			<input type="checkbox" id="ignore_missing" name="ignore_missing"<?php echo (!empty($options['ignore_missing']) ? ' checked="checked"' : ''); ?> />
			<label for="ignore_missing" class="fleft">Disable warnings for products found on the price list but not in the database</label>
			<div class="clear"></div><br>
			<input type="checkbox" id="upc_update" name="upc_update"<?php echo (!empty($options['upc_update']) ? ' checked="checked"' : ''); ?> />
			<label for="upc_update" class="fleft">Assign UPC codes if the price list contains that data (will not overwrite existing data)</label>
			<br><small>Note that matrix UPC codes will only be assigned or updated if also updating matrix prices</small>
			<div class="clear"></div><br>
			<input type="checkbox" id="upc_overwrite" name="upc_overwrite"<?php echo (!empty($options['upc_overwrite']) ? ' checked="checked"' : ''); ?> />
			<label for="upc_overwrite" class="fleft">Overwrite existing UPC codes when assigning them from the price list</label>
			<div class="clear"></div>
			<h4>MATRIX OPTIONS</h4>
			<?php echo (MATRIX_STATUS_ON ? '' : '<p>You must enable MATRIX_STATUS_ON in the script file in order to use any of the <strong>Matrix Options</strong>.</p>'); ?>
			<?php echo (empty($errors['matrix_options']) ? '' : '<p><span class="error">ERROR: ' . $errors['matrix_options'] . '</span></p>'); ?>
			<input type="checkbox" id="update_matrix" name="update_matrix"<?php echo (!empty($options['update_matrix']) ? ' checked="checked"' : ''); ?><?php echo (MATRIX_STATUS_ON ? '' : ' disabled="disabled"'); ?> />
			<label for="update_matrix" class="fleft">Update price entries in the option matrix</label>
			<div class="clear"></div>
			<small class="inner alert">Note that this requires your CubeCart installation to support both a 'price' and a 'sale_price' for the `option_matrix` table.</small>
			<br><small class="inner">This option must be checked to use any of the other matrix options.</small>
			<br><br>
			<input type="checkbox" id="update_main_price" name="update_main_price"<?php echo (!empty($options['update_main_price']) ? ' checked="checked"' : ''); ?><?php echo (MATRIX_STATUS_ON ? '' : ' disabled="disabled"'); ?> />
			<label for="update_main_price" class="fleft">Update pricing for the main product using the lowest non-zero priced (base, not sale) matrix option</label>
			<div class="clear"></div>
			<small class="inner alert">Note that this requires your CubeCart installation to support both a 'price' and a 'sale_price' for the `option_matrix` table.</small>
			<br><small class="inner">Note that pricing will only update if at least one of the product's prices (main product or matrix) changed.</small>
			<br><small class="inner">Useful if there is no 'general' price for the product, i.e. pricing is determined entirely by the chosen options / matrix.</small>
			<br><br>
			<input type="checkbox" id="disable_products" name="disable_products"<?php echo (!empty($options['disable_products']) ? ' checked="checked"' : ''); ?><?php echo (MATRIX_STATUS_ON ? '' : ' disabled="disabled"'); ?> />
			<label for="disable_products" class="fleft">Disable matrix entries with product codes not found on the price list, and possibly the parent product</label>
			<button type="submit" name="btn_disable" class="fright" title="Did you do a Dry Run? Please do so first!" <?php echo (MATRIX_STATUS_ON ? '' : ' disabled="disabled"'); ?>>Disable Only</button>
			<div class="clear"></div>
			<small class="inner alert">Note that this also requires your CubeCart installation to support the 'set_enabled' setting for the `option_matrix` table.</small>
			<br><small class="inner">Parent product is only disabled if all of its matrix options become disabled; products with no matrix options are not affected.</small>
			<br><small class="inner">Click <strong>Disable Only</strong> to check for and disable missing product / matrix codes only - you should still perform a <strong>Dry Run</strong> first.</small>
			<br><br>
			<button type="submit" name="btn_dry" title="View results without changing the database">Dry-Run</button>
			<button type="submit">Submit</button>
			<br><small>Note that the script may take up to 30 seconds or more to process if you have 10,000+ products to update. Please be patient.</small>
		</form>
		<?php if (isset($result) && is_array($result)) { ?>
			<h3><?php echo (empty($options['dry_run']) ? 'UPDATED' : 'DRY RUN'); ?> RESULTS</h3>
			<p>Completed <?php echo (empty($result['failed']) ? 'successfully' : 'with errors'); ?>.</p>
			<h4>Updated: <?php echo count($result['updated']); ?><span class="toggle_link">[<a id="updated-toggle" href="#updated-toggle" onclick="toggle('updated')">Show</a>]</span></h4>
			<div class="toggle" id="updated">
				<div class="light-border">
					<?php foreach ($result['updated'] as $updated) { ?>
						<?php echo '<p>' . htmlspecialchars($updated) . '</p>'; ?>
					<?php } ?>
				</div>
			</div>
			<h4>Disabled: <?php echo count($result['disabled']); ?><span class="toggle_link">[<a id="disabled-toggle" href="#disabled-toggle" onclick="toggle('disabled')">Show</a>]</span></h4>
			<div class="toggle" id="disabled">
				<div class="light-border">
					<p class="fright">[<a id="disabled_codes-toggle_all" href="#disabled_codes-toggle_all" onclick="toggleAll('disabled_codes')">Show All</a>]</p>
					<p>The following product / matrix codes exist in the database but were not found in the price list.</p>
					<?php if ($options['dry_run']) { ?>
					<p>Each of the following entries may be disabled upon running the script for real, thereby preventing their purchase in your store.</p>
					<p>Note that a product will only be disabled if it previously had one or more available matrix options and they were all disabled.</p>
					<?php } else { ?>
					<p>Each of the following entries <strong>has been disabled and may no longer be purchased</strong>.</p>
					<?php } ?>
					<?php foreach ($result['disabled'] as $product_id => $product_codes) { ?>
						<legend>Total for product ID <?php echo htmlspecialchars($product_id); ?>: <?php echo count($product_codes); ?> <span class="toggle_link">[<a id="disabled_codes-<?php echo $product_id; ?>-toggle" href="#disabled_codes-<?php echo $product_id; ?>-toggle" onclick="toggle('disabled_codes-<?php echo $product_id; ?>')">Show</a>]</span></legend>
						<div class="toggle" id="disabled_codes-<?php echo $product_id; ?>">
							<div class="light-border">
								<div class="inner">
									<p><?php echo (is_array($product_codes) ? nl2br(htmlspecialchars(implode("\n", $product_codes))) : htmlspecialchars($product_codes)); ?></p>
								</div>
							</div>
						</div>
					<?php } ?>
				</div>
			</div>
			<h4>Not Found: <?php echo count($result['not_found']); ?><span class="toggle_link">[<a id="not_found-toggle" href="#not_found-toggle" onclick="toggle('not_found')">Show</a>]</span></h4>
			<div class="toggle" id="not_found">
				<div class="light-border">
					<p>The following product / matrix codes were on the price list but could not be matched to a database entry.</p>
					<?php foreach ($result['not_found'] as $not_found) { ?>
						<?php echo '<p>' . htmlspecialchars($not_found) . '</p>'; ?>
					<?php } ?>
				</div>
			</div>
			<h4>Errors: <?php echo count($result['failed']); ?><span class="toggle_link">[<a id="failed-toggle" href="#failed-toggle" onclick="toggle('failed')">Show</a>]</span></h4>
			<div class="toggle" id="failed">
				<div class="light-border">
					<?php foreach ($result['failed'] as $failed) { ?>
						<?php echo '<p>' . htmlspecialchars($failed) . '</p>'; ?>
					<?php } ?>
				</div>
			</div>
			<h4>Warnings: <?php echo count($result['warning']); ?><span class="toggle_link">[<a id="warning-toggle" href="#warning-toggle" onclick="toggle('warning')">Show</a>]</span></h4>
			<div class="toggle" id="warning">
				<div class="light-border">
					<p class="fright">[<a id="warnings-toggle_all" href="#warnings-toggle_all" onclick="toggleAll('warnings')">Show All</a>]</p>
					<p>The following product codes exist in the database but were not found in the price list.<br>This may be caused by a simple mis-match in the product codes, e.g. 'ABC-1' vs. 'ABC1', or may be because the product code is actually missing.<br>Please double-check the following product codes, their prices, and that the products are still in production.</p>
					<?php foreach ($result['warning'] as $product_id => $product_codes) { ?>
						<legend>Total for product ID <?php echo htmlspecialchars($product_id); ?>: <?php echo count($product_codes); ?> <span class="toggle_link">[<a id="warnings-<?php echo $product_id; ?>-toggle" href="#warnings-<?php echo $product_id; ?>-toggle" onclick="toggle('warnings-<?php echo $product_id; ?>')">Show</a>]</span></legend>
						<div class="toggle" id="warnings-<?php echo $product_id; ?>">
							<div class="light-border">
								<div class="inner">
									<p><?php echo (is_array($product_codes) ? nl2br(htmlspecialchars(implode("\n", $product_codes))) : htmlspecialchars($product_codes)); ?></p>
								</div>
							</div>
						</div>
					<?php } ?>
					<p>If you performed a real update, these warnings may be reproduced by running the script again as a <strong>dry-run</strong>.</p>
				</div>
			</div>
		<?php } else { ?><br><?php } ?>
	</div>
	<div id="donate">
		<div class="light-border">
			<h3>Please Consider Donating</h3>
			<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
				<input type="hidden" name="cmd" value="_s-xclick">
				<input type="hidden" name="hosted_button_id" value="7TWFJJYMHJ7EQ">
				<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
				<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
			</form>
			<p><strong>Suggested donation</strong>: $5, $10, $20 or more, depending on how much time (and thus money!) you saved by using this software.</p>
			<p>Thanks for your consideration!</p>
		</div>
	</div>
	<div id="sidebar">
		<label for="navigable_tree" class="tree_header"><strong>DIRECTORY LISTING</strong></label>
		<br><?php echo treeToHtml($directories); ?>
	</div>
</body>
</html>
<script type="text/javascript">
/**
 * This function is based on Cory LaViska's PHP File Tree code,
 * available at http://www.abeautifulsite.net/php-file-tree/
 */
function init_tree() {
	if (!document.getElementsByTagName) { return; }
	var aMenus = document.getElementsByTagName("LI");
	for (var i = 0; i < aMenus.length; i++) {
		var mclass = aMenus[i].className;
		if (mclass.indexOf("leaf") > -1) {
			var submenu = aMenus[i].childNodes;
			for (var j = 0; j < submenu.length; j++) {
				if (submenu[j].tagName == "A") {
					submenu[j].onclick = function() {
						selectCategory(this.id);
						return false;
					}
				}
			}
		} else if (mclass.indexOf("branch") > -1) {
			var submenu = aMenus[i].childNodes;
			for (var j = 0; j < submenu.length; j++) {
				if (submenu[j].tagName == "A") {
					submenu[j].onclick = function() {
						var node = this.nextSibling;
						while (1) {
							if (node != null) {
								if (node.tagName == "UL") {
									if (node.style.display == "none") {
										node.style.display = "block";
										this.className = "open";
										//selectCategory(this.id);
									} else {
										node.style.display = "none";
										this.className = "closed";
									}
									return false;
								}
								node = node.nextSibling;
							} else {
								return false;
							}
						}
						return false;
					}
					submenu[j].className = (mclass.indexOf("open") > -1) ? "open" : "closed";
				}
				if (submenu[j].tagName == "UL") {
					submenu[j].style.display = (mclass.indexOf("open") > -1) ? "block" : "none";
				}
			}
		}
	}
	return false;
}

function selectCategory(id) {
	var input = document.getElementById('file');
	if (input) {
		input.value = id;
	}
}

function toggle(id) {
	var div = document.getElementById(id);
	if (div != null) {
		if (!div.style.display) {
			div.style.display = getComputedStyle(div, null).display;
		}
		div.style.display = (div.style.display == "none") ? "block" : "none";
		var link = document.getElementById(id + "-toggle");
		if (link != null) {
			if (div.style.display == "none") {
				link.innerHTML = link.innerHTML.replace("Hide", "Show");
			} else {
				link.innerHTML = link.innerHTML.replace("Show", "Hide");
			}
		}
	}
}

function toggleAll(id) {
	var link = document.getElementById(id + "-toggle_all");
	if (link != null) {
		var display = true;
		if (link.innerHTML.indexOf("Show") === 0) {
			link.innerHTML = link.innerHTML.replace("Show", "Hide");
			display = false;
		} else {
			link.innerHTML = link.innerHTML.replace("Hide", "Show");
		}
		var toggles = document.getElementsByClassName("toggle");
		for (var i = 0; i < toggles.length; ++i) {
			if (toggles[i].id.indexOf(id) === 0) {
				// set to opposite as #toggle will switch it back
				toggles[i].style.display = (display ? "none" : "block");
				toggle(toggles[i]);
			}
		}
	}
}

window.addEventListener("load", init_tree);
</script>
<?php
//=============== FUNCTIONS ===============//
/**
 * Return directory listing for selection, where each element contains
 * the contents of a directory, e.g.
 * [0] => array('name'=>'Directory1', 'directories'=>array()),
 * [1] => array('name'=>'Directory2', 'directories'=>array(
 *			[0] => array('name'=>'SubDirectory1', 'directories'=>array()),
 *			[1] => array('name'=>'SubDirectory2', 'directories'=>array())
 *		)
 * The full path would be composed of the name of each element leading up
 * to the leaf node appended onto the base path
 * @param $show_files true to list files as well as directories
 */
function getDirectories($dir, $show_files = false) {
	$directories = array();
	if (is_dir($dir)) {
		if ($dh = opendir($dir)) {
			while (($file = readdir($dh)) !== false) {
				if ($file[0] === '.') {
					continue; // skips ., .., and hidden files and directories
				} elseif (is_dir("$dir/$file")) {
					$directories[] = array('name'=>$file, 'directories'=>getDirectories("$dir/$file", $show_files));
				} elseif ($show_files) {
					$directories[] = array('name'=>$file);
				}
			}
			closedir($dh);
		}
	}
	return $directories;
}

/**
 * Recursively generates HTML code for displaying full directory tree
 * @param $branch	The branch on which to begin may be any sub-branch of the tree
 * @param $link		Full URL leading up to this branch, e.g. site/images/. Each directory will be appended to the end
 * @param $subnodes	Number of nested levels deep to go; if negative, full tree will be displayed
 * @param $current	Tracks current level for stopping recursion; should not be set manually
 * @param $first	Should only be true on the first call; no need to set manually
 */
function treeToHtml(array $branch, $link = '', $subnodes = -1, $current = 1, $first = TRUE) {
	$html = '<ul' . ($first ? ' id="navigable_tree" class="navigable_tree"' : '') . '>';
	foreach ($branch AS $k => $v) {
		$new_link = (empty($link) ? '' : "$link/") . urlencode($v['name']);
		if (empty($v['directories']) || ($subnodes > 0 && $current >= $subnodes)) {
			$html .= '<li class="leaf" id="leaf-' . $new_link . '"><a id="' . $new_link . '" href="#' . $new_link . '">' . htmlspecialchars($v['name']) . '</a></li>';
		} else {
			$html .= '<li class="branch" id="branch-' . $new_link . '"><a id="' . $new_link . '" href="#' . $new_link . '">' . htmlspecialchars($v['name']) . '</a>';
			if ($subnodes < 0 || $current < $subnodes) {
				$html .= treeToHtml($v['directories'], $new_link, $subnodes, $current + 1, false);
			}
			$html .= '</li>';
		}
	}
	return $html . '</ul>';
}

/**
 * Updates database prices from the supplied file
 * @param $filename string fully qualified filepath and name
 * @param $options  array of options; see declaration for details
 */
function updatePrices($dbc, $filename, array $options = array()) {
	$result = array('updated'=>array(), 'failed'=>array(), 'not_found'=>array(), 'warning'=>array(), 'disabled'=>array());
	$updated = array(); // store product_id => array of product codes matched for it
	$stmts = getPreparedStatements($dbc, $options);
	$labels = $options['header_labels'];
	try {
		$importer = new CsvImporter($filename, true, $labels, ",");
		$select_only = ($options['dry_run'] || $options['disable_only']);
		while ($data = $importer->get(2000)) {
			foreach ($data as $entry) {
				$manufacturer = (empty($entry[$labels['manufacturer']['label']]) ? $options['manufacturer'] : $entry[$labels['manufacturer']['label']]);
				$product_code = $entry[$labels['product_code']['label']];
				$upc = (!$options['upc_update'] || empty($entry[$labels['upc']['label']]) ? null : $entry[$labels['upc']['label']]);
				$list_price = round_up(getAmount($entry[$labels['list_price']['label']]), 2);
				$cost_price = (isset($entry[$labels['cost_price']['label']]) ? round_up(getAmount($entry[$labels['cost_price']['label']]), 2) : null);
				$sale_price = round_up(getAmount($entry[$labels['sale_price']['label']]), 2);
				if ($sale_price > $list_price && $options['allow_upsell']) {
					$list_price = $sale_price;
					$sale_price = null;
				}
				$changed = false; // flag indicating product (or matrix) prices changed
				if (!$stmts['select_product']->bind_param('ss', $product_code, $manufacturer) || !$stmts['select_product']->execute()) {
					throw new \RuntimeException("Query failed for manufacturer $manufacturer and product code $product_code: {$stmts['select_product']->errno} - {$stmts['select_product']->error}");
				}
				$main_product_id = fetch_assoc_stmt($stmts['select_product']);
				if ($select_only) {
					if (is_int($main_product_id)) {
						$result['updated'][] = "Product prices updated; Manufacturer: $manufacturer | Product Code: $product_code | List Price: \$" . sprintf('%.2f', $list_price) . " | Cost Price: \$" . sprintf('%.2f', $cost_price) . " | Sale Price: \$" . sprintf('%.2f', $sale_price);
						$changed = true;
					} elseif (!$options['ignore_missing']) {
						$result['not_found'][$product_code] = "Product was either not found or prices did not change; Manufacturer: $manufacturer | Product Code: $product_code";
					}
					if ($options['update_matrix']) {
						if (!$stmts['select_matrix']->bind_param('ss', $product_code, $manufacturer) || !$stmts['select_matrix']->execute()) {
							throw new \RuntimeException("Query failed for manufacturer $manufacturer and product code $product_code: {$stmts['select_matrix']->errno} - {$stmts['select_matrix']->error}");
						} elseif (!empty($product_id = fetch_assoc_stmt($stmts['select_matrix']))) {
							$result['updated'][] = "Matrix prices updated; Manufacturer: $manufacturer | Product Code: $product_code | List Price: \$" . sprintf('%.2f', $list_price) . " | Sale Price: \$" . sprintf('%.2f', $sale_price);
							$changed = true;
							$updated[$product_id][] = $product_code;
							// wasn't found as a product, but found as a matrix entry
							if (array_key_exists($product_code, $result['not_found'])) {
								unset($result['not_found'][$product_code]); 
							}
						} elseif (array_key_exists($product_code, $result['not_found'])) {
							$result['not_found'][$product_code] = "Neither product nor matrix entry not found; Manufacturer: $manufacturer | Product Code: $product_code";
						}
					}
				} else {
					if (!$stmts['update_product']->bind_param('dddsi', $list_price, $cost_price, $sale_price, $upc, $main_product_id) || !$stmts['update_product']->execute()) {
						throw new \RuntimeException("Query failed for manufacturer $manufacturer and product code $product_code: {$stmts['update_product']->errno} - {$stmts['update_product']->error}");
					} elseif ($stmts['update_product']->affected_rows > 0) {
						$result['updated'][] = "Product prices updated; Manufacturer: $manufacturer | Product Code: $product_code | List Price: \$" . sprintf('%.2f', $list_price) . " | Cost Price: \$" . sprintf('%.2f', $cost_price) . " | Sale Price: \$" . sprintf('%.2f', $sale_price);
						$changed = true;
					} elseif (!$options['ignore_missing']) {
						$result['not_found'][$product_code] = "Product was either not found or prices did not change; Manufacturer: $manufacturer | Product Code: $product_code";
					}
					if ($options['update_matrix']) {
						if (!$stmts['update_matrix']->bind_param('ddsss', $list_price, $sale_price, $upc, $product_code, $manufacturer) || !$stmts['update_matrix']->execute()) {
							throw new \RuntimeException("Query failed for manufacturer $manufacturer and product code $product_code: {$stmts['update_matrix']->errno} - {$stmts['update_matrix']->error}");
						} elseif ($stmts['update_matrix']->affected_rows > 0) {
							if (!$stmts['select_matrix']->bind_param('ss', $product_code, $manufacturer) || !$stmts['select_matrix']->execute()) {
								throw new \RuntimeException("Query to select product id from matrix table failed for manufacturer $manufacturer and product code $product_code: {$stmts['select_matrix']->errno} - {$stmts['select_matrix']->error}");
							} elseif (empty($product_id = fetch_assoc_stmt($stmts['select_matrix']))) {
								$result['failed'][] = "Matrix entry not found after updating! Manufacturer: $manufacturer | Product Code: $product_code";
							} else {
								$result['updated'][] = "Matrix prices updated; Manufacturer: $manufacturer | Product Code: $product_code | List Price: \$" . sprintf('%.2f', $list_price) . " | Sale Price: \$" . sprintf('%.2f', $sale_price);
								$changed = true;
								$updated[$product_id][] = $product_code;
								// wasn't found as a product, but found as a matrix entry
								if (array_key_exists($product_code, $result['not_found'])) {
									unset($result['not_found'][$product_code]); 
								}
							}
						} elseif (array_key_exists($product_code, $result['not_found'])) {
							$result['not_found'][$product_code] = "Neither product nor matrix entry was found or updated; Manufacturer: $manufacturer | Product Code: $product_code";
						}
					}
				}
				// Product was found and updated - update 'date updated' field
				$id = ($main_product_id ? $main_product_id : $product_id);
				if ($id && empty($result['updated']["Date-$id"]) && ($options['update_date_all'] || ($changed && $options['update_date']))) {
					if ($select_only) {
						$result['updated']["Date-$id"] = "Product id $id: Date modified updated for $manufacturer product $product_code";
					} elseif (!$stmts['update_date']->bind_param('i', $id) || !$stmts['update_date']->execute()) {
						throw new \RuntimeException("Update date query failed for manufacturer $manufacturer and product code $product_code: {$stmts['update_date']->errno} - {$stmts['update_date']->error}");
					} else {
						$result['updated']["Date-$id"] = "Product id $id: Date modified updated for $manufacturer product $product_code";
					}
				}
			}
		}
		// TODO option to disable warnings (including display thereof)
		// Array only contains entries when updating matrix codes, i.e. option_matrix table has been modified accordingly
		foreach ($updated as $product_id => $product_codes) {
			// select all product / matrix codes from database for this product
			if (!$stmts['select_product_codes']->bind_param('ii', $product_id, $product_id) || !$stmts['select_product_codes']->execute()) {
				throw new \RuntimeException("Query to select product codes while checking for warnings failed for product id $product_id: {$stmts['select_product_codes']->errno} - {$stmts['select_product_codes']->error}");
			}
			// disable / warn for any that were not found on the price list
			$codes = fetch_assoc_stmt($stmts['select_product_codes']);
			$diff = array_diff((is_array($codes) ? $codes : array($codes)), $product_codes);
			if ($options['disable_products']) {
				if ($options['dry_run']) {
					$result['disabled'][$product_id] = $diff;
				} else {
					// Disable matrix entries first
					foreach ($diff as $product_code) {
						if (!$stmts['disable_matrix']->bind_param('is', $product_id, $product_code) || !$stmts['disable_matrix']->execute()) {
							throw new \RuntimeException("Failed to disable matrix entry for product $product_id - $product_code: {$stmts['disable_matrix']->errno} - {$stmts['disable_matrix']->error}");
						} elseif ($stmts['disable_matrix']->affected_rows > 0) {
							$result['disabled'][$product_id][] = $product_code;
						} else {
							$result['warning'][$product_id][] = "Matrix entry for product $product_id - $product_code could not be disabled: it may already be disabled, but you should double-check";
						}
					}
					// Then disable products that no longer have any enabled matrix options
					if (!$stmts['disable_product']->bind_param('iii', $product_id, $product_id, $product_id) || !$stmts['disable_product']->execute()) {
						throw new \RuntimeException("Failed to disable product id $product_id: {$stmts['disable_product']->errno} - {$stmts['disable_product']->error}");
					} elseif ($stmts['disable_product']->affected_rows > 0) {
						$result['disabled'][$product_id][] = "Product $product_id disabled";
					} else {
						$result['warning'][$product_id][] = "Product $product_id was not be disabled: it may either not need to be or already is disabled; you should double-check";
					}
				}
			} elseif (!empty($diff)) {
				$result['warning'][$product_id] = $diff;
			}
			// Update main product price with the lowest (non-zero) of its enabled matrix options
			if ($options['update_main_price']) {
				if (!$stmts['lowest_price']->bind_param('i', $product_id) || !$stmts['lowest_price']->execute()) {
					throw new \RuntimeException("Failed to fetch lowest matrix price for product $product_id: {$stmts['lowest_price']->errno} - {$stmts['lowest_price']->error}");
				}
				$prices = fetch_assoc_stmt($stmts['lowest_price']);
				if (!empty($prices)) {
					extract($prices);
					if (!$stmts['update_main_price']->bind_param('ddi', $price, $sale_price, $product_id) || !$stmts['update_main_price']->execute()) {
						throw new \RuntimeException("Failed to update main prices for product $product_id: {$stmts['update_main_price']->errno} - {$stmts['update_main_price']->error}");
					} elseif ($stmts['update_main_price']->affected_rows > 0) {
						$result['updated'][] = "Main prices for product id $product_id set to lowest found in matrix: List Price=\$$price, Sale Price=\$".($sale_price ? $sale_price : '0.00');
					} else {
						$result['warning'][$product_id][] = "Failed to update prices to \$$price (sale: \$".($sale_price ? $sale_price : '0.00').") - prices may already be up-to-date";
					}
				}
			}
		}
	} catch (\Exception $e) {
		throw $e;
	} finally {
		foreach ($stmts as $stmt) { $stmt->close(); }
	}
	return $result;
}

/**
 * Fetches the results of a prepared statement as an array of associative
 * arrays such that each stored array is keyed by the result's column names.
 * @param stmt   Must have been successfully prepared and executed prior to calling this function
 * @param force_assoc If true, the result set will always be returned as an associative array, even if there is only one item
 * @param buffer Whether to buffer the result set; if true, results are freed at end of function
 * @return	1. A single value, e.g. 'Some Value' or an unkeyed array of single values if more than one row resulted and $force_assoc is false
 *			2. A single associative array if there is only one resulting row
 *			3. An array with one associative array per result row (empty array if no results)
 */
function fetch_assoc_stmt($stmt, $force_assoc = false, $buffer = true) {
	if ($buffer) {
		$stmt->store_result();
	}
	$fields = $stmt->result_metadata()->fetch_fields();
	$flag = (count($fields) === 1) && !$force_assoc; // no need to keep associative array if only one column selected
	$args = array();
	foreach($fields AS $field) {
		if ($flag) {
			$args[] = &$field->name; // this way the array key is also preserved
		} else {
			$key = str_replace(' ', '_', $field->name); // space may be valid SQL, but not PHP
			$args[$key] = &$field->name; // this way the array key is also preserved
		}
	}
	call_user_func_array(array($stmt, "bind_result"), $args);
	$results = array();
	$tmp = array();
	while($stmt->fetch()) {
		$results[] = ($flag ? copy_value($args[0]) : array_map("copy_value", $args));
	}
	if ($buffer) {
		$stmt->free_result();
	}
	return (empty($results) || $force_assoc || count($results) > 1 ? $results : (is_array($results) ? $results[0] : $results));
}

/**
 * Returns a value by value rather than reference (useful for handling prepared statement result sets)
 */
function copy_value($v) {
	return $v;
}

/**
 * De-formats a currency string into standard float amount.
 * Adapted from StackOverflow: http://stackoverflow.com/a/19764699
 */
function getAmount($money) {
	$cleanString = preg_replace('/([^0-9\.,])/i', '', $money);
	$onlyNumbersString = preg_replace('/([^0-9])/i', '', $money);
	$separatorsCountToBeErased = strlen($cleanString) - strlen($onlyNumbersString) - 1;
	return (float) preg_replace('/([,\.])/', '', $cleanString, $separatorsCountToBeErased);
}

/** @author mvds from http://stackoverflow.com/questions/8771842/always-rounding-decimals-up-to-specified-precision */
function round_up($in, $prec) {
	$fact = pow(10, $prec);
	return ceil($fact * $in) / $fact;
}

/** This function copied from filemanager.class.php as found in CubeCart v6.0.8 */
function getMimeType($file) {
	$finfo = (extension_loaded('fileinfo')) ? new finfo(FILEINFO_MIME_TYPE) : false;
	if ($finfo && $finfo instanceof finfo) {
		$mime = $finfo->file($file);
	} else if (function_exists('mime_content_type')) {
		$mime = mime_content_type($file);
	} else {
		$data = getimagesize($file);
		$mime = $data['mime'];
	}
	return (empty($mime)) ? 'application/octet-stream' : $mime;
}

function getPreparedStatements($dbc, array $options = array()) {
	$prefix = (empty(TABLE_PREFIX) ? '' : TABLE_PREFIX) . 'CubeCart';
	$stmts = array();
	// Select product ID
	$q = "SELECT i.product_id FROM `$prefix" . "_inventory` i JOIN `$prefix" . "_manufacturers` mf ON mf.id=i.manufacturer WHERE i.product_code=? AND mf.name=? LIMIT 1";
	$stmts['select_product'] = $dbc->prepare($q);
	if (!$options['dry_run'] && !$options['disable_only']) {
		// update prices for matching products
		// params = 'dddsi', price, cost_price, sale price, upc, product id
		$q = "UPDATE `$prefix" . "_inventory` i SET " . ($options['enable_updated'] ? ' i.status=1, ' : '') . "i.price=?, i.cost_price=COALESCE(?, i.cost_price), i.sale_price=?, i.upc=" . ($options['upc_update'] && $options['upc_overwrite'] ? '?' : "COALESCE(IF(i.upc='', NULL, i.upc), ?)") . " WHERE i.product_id=?";
		$stmts['update_product'] = $dbc->prepare($q);
		
		// update prices for matching option matrix entries (doesn't use product_id as that may not have been found)
		// params = 'ddsss', price, sale price, upc, product code, manufacturer
		if ($options['update_matrix']) {
			$q = "UPDATE `$prefix" . "_option_matrix` m JOIN `$prefix" . "_inventory` i ON i.product_id=m.product_id JOIN `$prefix" . "_manufacturers` mf ON mf.id=i.manufacturer SET " . ($options['enable_updated'] ? ' m.set_enabled=1, ' : '') . "m.price=?, m.sale_price=?, m.upc=" . ($options['upc_update'] && $options['upc_overwrite'] ? '?' : "COALESCE(IF(m.upc='', NULL, m.upc), ?)") . " WHERE m.product_code=? AND mf.name=?";
			$stmts['update_matrix'] = $dbc->prepare($q);
		}
	}
	// Queries used both in dry-run and actual processing
	if ($options['update_matrix']) {
		$q = "SELECT m.product_id FROM `$prefix" . "_option_matrix` m JOIN `$prefix" . "_inventory` i ON i.product_id=m.product_id JOIN `$prefix" . "_manufacturers` mf ON mf.id=i.manufacturer WHERE m.product_code=? AND mf.name=? LIMIT 1";
		$stmts['select_matrix'] = $dbc->prepare($q);
		
		// Find all product codes for a product, including those from the option matrix
		// params = 'ii', product_id x 2
		$q = "SELECT product_code FROM `$prefix" . "_inventory` WHERE product_id=? UNION ALL SELECT product_code FROM (SELECT product_code FROM `$prefix" . "_option_matrix` WHERE product_id=? ORDER BY product_code) AS b";
		$stmts['select_product_codes'] = $dbc->prepare($q);
		
		if ($options['update_main_price']) {
			// Select lowest non-zero price from matrix for a given product id
			$q = "SELECT `price`, `sale_price` FROM `$prefix" . "_option_matrix` WHERE `product_id`=? AND `status`=1 AND `set_enabled`=1 AND `price` > 0 ORDER BY `price` ASC LIMIT 1";
			$stmts['lowest_price'] = $dbc->prepare($q);
			
			// Update the main product's pricing with the 'lowest_price' from above
			$q = "UPDATE `$prefix" . "_inventory` SET `price`=?, `sale_price`=? WHERE `product_id`=?";
			$stmts['update_main_price'] = $dbc->prepare($q);
		}
	}
	// update product entry's modification date
	// params = 'i', product id
	if ($options['update_date'] || $options['update_date_all']) {
		$q = "UPDATE `$prefix" . "_inventory` i SET i.updated=CURRENT_TIMESTAMP WHERE i.product_id=?";
		$stmts['update_date'] = $dbc->prepare($q);
	}
	//=== Statements for updating 'enabled' status of products and, if supported, matrix entries ===//
	if ($options['disable_products']) {
		// Disable matrix entries that weren't found in the price list; params = 'is', product id, matrix product code
		$q = "UPDATE `$prefix" . "_option_matrix` SET `set_enabled`=0 WHERE product_id=? AND product_code=?";
		$stmts['disable_matrix'] = $dbc->prepare($q);
		// Disable a product whose code wasn't found in the price list, but only if it doesn't have any enabled matrix entries
		// params = 'iii', product_id x 3
		$q = "UPDATE `$prefix" . "_inventory` SET `status`=0, updated=CURRENT_TIMESTAMP WHERE product_id=? AND EXISTS (SELECT 1 FROM `$prefix" . "_option_matrix` m WHERE m.product_id=?  AND m.status=1 LIMIT 1) AND NOT EXISTS (SELECT 1 FROM `$prefix" . "_option_matrix` m WHERE m.product_id=? AND m.status=1 AND m.set_enabled=1 LIMIT 1)";
		$stmts['disable_product'] = $dbc->prepare($q);
	}
	return $stmts;
}

/**
 * Yoink!
 * @author myrddin at myrddin dot myrddin from http://php.net/manual/en/function.fgetcsv.php
 */
class CsvImporter
{
	private $fp;
	private $parse_header;
	private $header;
	private $delimiter;
	private $length;
	
	function __construct($file_name, $parse_header=false, $header_labels=array(), $delimiter="\t", $length=8000) {
		$this->fp = fopen($file_name, "r");
		if (!($this->fp)) {
			throw new \InvalidArgumentException("Invalid file name: {$this->fp}");
		}
		$this->parse_header = $parse_header;
		$this->delimiter = $delimiter;
		$this->length = $length;
		if ($this->parse_header) {
			$this->header = fgetcsv($this->fp, $this->length, $this->delimiter);
			if (!($this->header)) {
				throw new \InvalidArgumentException("Error reading header for file: {$this->fp}");
			} elseif (!empty($header_labels)) {
				$missing = array();
				foreach ($header_labels as $label) {
					if (!empty($label['required']) && false === array_search($label['label'], $this->header)) {
						$missing[] = $label['label'];
					}
				}
				if (!empty($missing)) {
					throw new \InvalidArgumentException("Missing header labels: " . implode(', ', $missing));
				}
			}
		}
	}
	
	function __destruct() {
		if ($this->fp) {
			fclose($this->fp);
		}
	}
	
	function get($max_lines=0) {
		//if $max_lines is set to 0, then get all the data
		$data = array();
		if ($max_lines > 0) {
			$line_count = 0;
		} else {
			$line_count = -1; // so loop limit is ignored
		}
		while ($line_count < $max_lines && ($row = fgetcsv($this->fp, $this->length, $this->delimiter)) !== FALSE) {
			if ($this->parse_header) {
				foreach ($this->header as $i => $heading_i) {
					$row_new[$heading_i] = $row[$i];
				}
				$data[] = $row_new;
			} else {
				$data[] = $row;
			}
			if ($max_lines > 0) {
				$line_count++;
			}
		}
		return $data;
	}
} 
?>
