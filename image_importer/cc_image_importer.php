<?php
/*
Simple importer script to add database entries for pre-uploaded images, rather
than adding them each individually from CubeCart's admin interface. Optionally
adds image:product and/or image:matrix relationships for each image processed.

INSTALLATION
1. Edit the variables under USER SETTINGS below
2. Upload the modified version of this script to any accessible path on your website, e.g. yoursite.com/utils/
3. Ready to use:
	- Navigate to the script URL, e.g. https://www.yoursite.com/utils/cc_image_importer.php
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

// Change to false if your database tables do not support transactions
// (e.g. they do not use the InnoDB storage engine)
DEFINE('USE_TRANSACTIONS', true);

// Path to public folder on your web server where images are stored
DEFINE('PATH', '/home/yourname/public_html/images/source/'); // make sure it ends with a '/'!!!

//====================================================//
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<title>Image Importer</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width" />
	<meta name="title" content="Image Importer" />
	<meta name="description" content="Import previously uploaded images into the CubeCart database" /> 
	<meta name="author" content="Brian Sandall" /> 
</head>
<?php
$errors = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$dir = trim(filter_input(INPUT_POST, 'dir'));
	if (!empty($dir) && is_string($dir)) {
		if (strpos($dir, '..') !== false) {
			$errors['dir'] = '* Invalid directory';
		} elseif (in_array(strtolower($dir), array('.','/'))) {
			$dir = '';
		} else {
			$dir = ltrim(rtrim($dir, '\/'), '\/');
			if (!is_file(PATH . $dir)) {
				$dir .= '/';
			}
		}
	} else {
		$errors['dir'] = '* Required field';
	}
	if (empty($errors) && !is_dir(PATH . $dir) && !is_file(PATH . $dir)) {
		$errors['dir'] = '* Invalid directory or file';
	}
	$code_suffix = filter_input(INPUT_POST, 'code_suffix');
	if (!is_string($code_suffix) || $code_suffix === '_' || $code_suffix === '-') {
		$code_suffix = '';
	} elseif (!preg_match('/^[-_a-z0-9]*$/i', $code_suffix)) {
		$errors['code_suffix'] = '* Valid characters are -, _, a-z, and 0-9';
	}
	$options = array(
		/** true to perform a dry-run that doesn't change the database */
		'dry_run'            => isset($_POST['btn_dry']),
		/** true to include all sub-directories */
		'recursive'          => (filter_input(INPUT_POST, 'recursive') ? true : false),
		/** true to update file size values in the database for existing files */
		'update_size'        => (filter_input(INPUT_POST, 'update_size') ? true : false),
		/** true to add product:image relationships */
		'add_product'        => (filter_input(INPUT_POST, 'add_product') ? true : false),
		/** true to use image as a main image */
		'main_image'         => (filter_input(INPUT_POST, 'main_image') ? '1' : '0'),
		/** true to add product:image relationships for each matching matrix entry */
		'add_product_matrix' => (filter_input(INPUT_POST, 'add_product_matrix') ? true : false),
		/** true to add matrix:image relationships */
		'update_matrix'      => (filter_input(INPUT_POST, 'update_matrix') ? true : false),
		/** true to overwrite previous matrix:image relationships */
		'force_update'       => (filter_input(INPUT_POST, 'force_update') ? true : false),
		/** true to skip processing of files already in the filemanager */
		'ignore_existing'    => (filter_input(INPUT_POST, 'ignore_existing') ? true : false),
		/** whether to allow multiple image variants for a single product */
		'allow_variants'     => (filter_input(INPUT_POST, 'allow_variants') ? true : false),
		/** custom naming convention, if any, used to match multiple image filenames to a single product */
		'code_suffix'        => $code_suffix,
		/** custom regexp used to match multiple products to a single image file */
		'regexp'             => filter_input(INPUT_POST, 'regexp')
	);
	$show_advanced = !empty($code_suffix) || !empty($options['regexp']);
	$show_options = ($show_advanced || !empty($options['add_product']) || !empty($options['add_product_matrix']) || !empty($options['update_matrix']) || !empty($options['code_suffix']));
	if (empty($errors)) {
		$dbc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
		if (mysqli_connect_errno()) {
			die("Could not connect to MySQL: " . mysqli_connect_error());
		}
		$stmts = getPreparedStatements($dbc, $options);
		if (USE_TRANSACTIONS) {
			$dbc->autocommit(false);
			try {
				$result = addFiles($dir, $stmts, $options);
				// Rollback any changes that may have occurred during dry run (no changes should have been made, but just in case)
				if (!empty($options['dry_run'])) {
					throw new \Exception("Dry run completed successfully");
				}
			} catch (\Exception $e) {
				$result['failed'][] = "Rolling back changes due to exception: {$e->getMessage()}";
				if (!$dbc->rollback()) {
					$result['failed'][] = "CRITICAL: Failed to rollback database!";
				}
			} finally {
				if (!$dbc->autocommit(true)) {
					$result['failed'][] = "Failed to commit database changes: $dbc->errno - $dbc->error";
				}
			}
		} else {
			$result = addFiles($dir, $stmts, $options);
		}
		foreach ($stmts as $stmt) {
			if ($stmt) { $stmt->close(); }
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
	.clear { clear:both;}
	.toggle { display:none;}
	.wide { width: 80%;}
	.light-border { border: 1px solid black; margin-bottom:20px;}
	.light-border > .inner { margin-left: 20px; margin-right: 20px;}
	ul.navigable_tree { margin:0; list-style:none; float:left;}
	li > ul { padding-left:30px; list-style:none;}
	ul.navigable_tree, .tree_header { padding-left:40px;}
	li.branch, li.branch > a { color:blue; }
	li.leaf, li.leaf > a { color:orange; text-decoration:none;}
	input, label { margin-right: 20px;}
	input[type=checkbox] { float:left;}
	input.error { border: 2px solid #800517;}
	span.error { color: #800517; font-weight: bold;}
</style>
<body>
	<div id="content">
		<h3>CubeCart Image Importer</h3>
		<p>Select an image or image directory on your server to automatically add its contents to CubeCart's file database.</p>
		<p>Optionally attempt to add product:image and matrix:image relationships for each image processed. Relationships are determined by comparing the filename to the product code; see the 'Details' section under 'Options: Image Relationships' for more information.</p>
		<form method="post" action="#" accept-charset="utf-8">
			<label for="dir"><strong>Directory or File to Import</strong></label><br>
			<small>Enter a folder path or filename, or use the 'Directory Listing' tree to the right to select one.</small><br>
			<small>Paths are relative to: <em><?php echo PATH; ?></em></small><br>
			<small>Enter '.' or '/' to import from the directory noted above.</small><br>
			<input type="text" id="dir" name="dir" class="wide<?php echo (empty($errors['dir']) ? '' : ' error'); ?>" <?php echo (isset($dir) ? ' value="' . $dir . '"' : ''); ?> />
			<?php echo (empty($errors['dir']) ? '' : '<br><span class="error">' . $errors['dir'] . '</span>'); ?>
			<br>
			<h4>Options: Importing Images</h4>
			<p>Options related directly to importing image files.</p>
			<input type="checkbox" id="recursive" name="recursive"<?php echo (isset($options['recursive']) && !$options['recursive'] ? '' : ' checked="checked"'); ?> />
			<label for="recursive" class="fleft">Include Sub-Directories</label>
			<div class="clear"></div><br>
			<input type="checkbox" id="update_size" name="update_size"<?php echo (!empty($options['update_size']) ? ' checked="checked"' : ''); ?> />
			<label for="update_size" class="fleft">Update size of existing images (e.g. if it was resized after being added)</label>
			<div class="clear"></div>
			<h4>Options: Image Relationships [ <a id="options-toggle" href="#options-toggle" onclick="toggle('options')"><?php echo (empty($show_options) ? 'Show' : 'Hide'); ?></a> ]</h4>
			<div <?php echo (empty($show_options) ? 'class="toggle" ' : ''); ?>id="options">
				<div class="light-border"><div class="inner">
					<p>Options related to creating relationships between imported image files and existing products.</p>
					<input type="checkbox" id="add_product" name="add_product"<?php echo (empty($options['add_product']) ? '' : ' checked="checked"'); ?> />
					<label for="add_product" class="fleft">Add product:image relationships for product matches</label>
					<br><small>An image is considered to match a product when the filename (not including file extensions) is identical to the product code.</small>
					<div class="clear"></div><br>
					<input type="checkbox" id="add_product_matrix" name="add_product_matrix"<?php echo (empty($options['add_product_matrix']) ? '' : ' checked="checked"'); ?> />
					<label for="add_product_matrix" class="fleft">Add product:image relationships for product matrix matches</label>
					<br><small>Don't check this if you want matrix images separate from the main product!</small>
					<div class="clear"></div><br>
					<input type="checkbox" id="main_image" name="main_image"<?php echo (empty($options['main_image']) ? '' : ' checked="checked"'); ?> />
					<label for="main_image" class="fleft">Add each image as a 'Main Image'</label>
					<div class="clear"></div><br>
					<input type="checkbox" id="update_matrix" name="update_matrix"<?php echo (empty($options['update_matrix']) ? '' : ' checked="checked"'); ?> />
					<label for="update_matrix" class="fleft">Update the 'option_matrix.image' column when the above relationship is added</label>
					<br><small>Note that the default CubeCart skin does not support displaying individual matrix images, and custom skins may handle them differently.</small>
					<br><small>As such, checking this option may not have any observable effect in your store.</small>
					<div class="clear"></div><br>
					<input type="checkbox" id="force_update" name="force_update"<?php echo (empty($options['force_update']) || $options['force_update'] == '0' ? '' : ' checked="checked"'); ?> />
					<label for="force_update" class="fleft">Overwrite any existing data when updating the 'option_matrix.image' column</label>
					<div class="clear"></div><br>
					<input type="checkbox" id="ignore_existing" name="ignore_existing"<?php echo (isset($options['ignore_existing']) && !$options['ignore_existing'] ? '' : ' checked="checked"'); ?> />
					<label for="ignore_existing" class="fleft">Add relationships for new files only</label>
					<div class="clear"></div>
					<h4>Advanced [ <a id="advanced-toggle" href="#advanced-toggle" onclick="toggle('advanced')"><?php echo (empty($show_advanced) ? 'Show' : 'Hide'); ?></a> ]</h4>
					<div <?php echo (empty($show_advanced) ? 'class="toggle" ' : ''); ?>id="advanced">
						<div class="light-border"><div class="inner">
							<h3>Image Variants</h3>
							<p>Multiple image relationships can be created by naming your files e.g. 'code-1.jpg', 'code_2.gif', etc., using either a hyphen, underscore, or neither before a number.</p>
							<p>You may optionally define your own variant naming convention, e.g. '-var' would match 'ABC123-var0', 'ABC123-var-1', and 'ABC123-var_2' to the product code 'ABC123', but would not match 'ABC1234' or 'ABC123-1'</p>
							<p>If the filename is an exact match for a matrix entry's product code, that will take precedence over adding the file as an additional image for the main product.</p>
							<p>Note that this works best when the product(s) with all related options and matrix entries have already been added.</p>
							<input type="checkbox" id="allow_variants" name="allow_variants"<?php echo (isset($options['allow_variants']) && !$options['allow_variants'] ? '' : ' checked="checked"'); ?> />
							<label for="allow_variants" class="fleft">Allow multiple image variants per product (as described above)</label>
							<div class="clear"></div><br>
							<label for="code_suffix"><strong>Naming Convention</strong></label>
							<input type="text" id="code_suffix" name="code_suffix"<?php echo (empty($errors['code_suffix']) ? '' : ' class="error"'); ?> value="<?php echo (empty($options['code_suffix']) ? '' : htmlspecialchars($options['code_suffix'])); ?>" placeholder="e.g. -var" />
							<br><small>Has no effect if image variants are not allowed (see previous checkbox)</small>
							<?php echo (empty($errors['code_suffix']) ? '' : '<br><span class="error">' . $errors['code_suffix'] . '</span>'); ?>
							<br>
							<h3>Advanced Matching</h3>
							<p>Enter a custom regular expression for matching the image filename(s) to product codes.</p>
							<label for="regexp"><strong>Regular Expression</strong></label>
							<input type="text" id="regexp" name="regexp"<?php echo (empty($errors['regexp']) ? '' : ' class="error"'); ?> value="<?php echo (empty($options['regexp']) ? '' : htmlspecialchars($options['regexp'])); ?>" placeholder="e.g. ^CodeY(S|M|L)$" />
							<p>Example: Assuming you have a product that comes in various colors (e.g. R, Y, B) and sizes (e.g. S, M, L) with product codes in the format 'CodeColorSize', if you wanted to use the same image for every yellow version of the product, you could enter <em>^CodeY(S|M|L)$</em>; this would match products and matrix entries for CodeYS, CodeYM, and CodeYL.</p>
							<p>WARNINGS<p><ol><li>Do not use if you are not comfortable with regular expressions</li><li>Not recommended for use with directories, i.e. use for one image file at a time</li><li>Always perform a <strong>Dry Run</strong> first!</li></ol>
						</div>
					</div></div>
				</div></div>
			</div>
			<button type="submit" name="btn_dry" title="View results without changing the database">Dry-Run</button>
			<button type="submit">Submit</button>
		</form>
		<?php if (isset($result) && is_array($result)) { ?>
			<h3><?php echo (empty($options['dry_run']) ? 'IMPORT' : 'DRY RUN'); ?> RESULTS</h3>
			<p>Added: <?php echo count($result['added']); ?></p>
			<?php foreach ($result['added'] as $added) { ?>
				<?php echo "<p>$added</p>"; ?>
			<?php } ?>
			<p>Errors: <?php echo count($result['failed']); ?></p>
			<?php foreach ($result['failed'] as $failed) { ?>
				<?php echo "<p>$failed</p>"; ?>
			<?php } ?>
		<?php } ?>
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
		<label for="navigable_tree" class="tree_header"><strong>Directory Listing</strong></label>
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
										selectCategory(this.id);
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
	var input = document.getElementById('dir');
	if (input) {
		input.value = decodeURIComponent(id);
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
		$new_link = (empty($link) ? '' : "$link/") . rawurlencode($v['name']);
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
 * Recursively traverses directories calling #addFile on each file encountered
 * @param $dir   string name of directory relative to PATH in the format 'some/directory/'; may be an empty string
 * @param $stmts array of prepared statements; see #getPreparedStatements for details
 * @param $options array of options; see declaration for details
 */
function addFiles($dir, array $stmts, array $options = array()) {
	$results = array('added'=>array(),'failed'=>array());
	$path = PATH . $dir;
	if (is_dir($path)) {
		if ($dh = opendir($path)) {
			while (($file = readdir($dh)) !== false) {
				if ($file[0] === '.') {
					continue; // skips ., .., and hidden files and directories
				} elseif (is_dir($path.$file)) {
					if (!empty($options['recursive'])) {
						$results = array_merge_recursive($results, addFiles("$dir$file/", $stmts, $options));
					}
				} elseif (addFile($dir, $file, $stmts, $options, $message)) {
					$results['added'][] = "File: $path" . formatName($file) . $message;
				} else {
					$results['failed'][] = "File: " . PATH . $dir . formatName($file) . $message;
				}
			}
			closedir($dh);
		}
	} elseif (is_file($path)) {
		$parts = explode('/', $dir);
		$file = array_pop($parts);
		$dir = implode('/', $parts);
		if (!empty($dir)) {
			$dir .= '/';
		}
		if (addFile($dir, $file, $stmts, $options, $message)) {
			$results['added'][] = "File: " . PATH . $dir . formatName($file) . $message;
		} else {
			$results['failed'][] = "File: " . PATH . $dir . formatName($file) . $message;
		}
	} else {
		$results['failed'][] = "Invalid file or directory: $path";
	}
	return $results;
}

/**
 * Adds the image file and, depending on options, the image relationships to the database
 * @param $dir     path relative to PATH
 * @param $file    name of file located at PATH/$path/
 * @param $stmts   same as array of prepared statements passed to #addFiles
 * @param $options same as options array passed to #addFiles
 * @param $message string containing additional information
 * @return true on success
 */
function addFile($dir, $file, array $stmts, array $options, &$message) {
	$message = '';
	$path = PATH . $dir;
	$formatted = formatName($file);
	if (empty($dir)) {
		$dir = null; // set empty string to null for 'image_exists' and 'add_image' queries
	}
	if (file_exists($path.$file) && $formatted !== $file && !rename($path.$file, $path.$formatted)) {
		$message .= "<br>ERROR: Failed to rename file `$path$file` to `$path$formatted`";
		return false;
	}
	$file = $formatted;
	$filepath = $path.$file;
	if (file_exists($filepath)) {
		$mime = getMimeType($filepath);
		if (strpos($mime, 'image/') !== 0) {
			$message .= "<br>ERROR: Invalid mime type $mime";
			return false;
		} elseif (!$stmts['image_exists']->bind_param('sss', $dir, $dir, $file) || !$stmts['image_exists']->execute()) {
			$message .= "<br>ERROR: Database error checking if image exists: $stmts[image_exists]->errno - $stmts[image_exists]->error";
			return false;
		} elseif (is_int($file_id = fetch_assoc_stmt($stmts['image_exists'], false))) {
			$message .= "<br>NOTICE: File entry already exists - file_id = $file_id";
			// Check if file sizes should be updated
			if (!empty($options['update_size'])) {
				if (false === ($size = filesize($filepath))) {
					$message .= "<br>ERROR: Failed to determine file size";
					return false;
				} elseif (!$stmts['update_size']->bind_param('ii', $size, $file_id) || !$stmts['update_size']->execute()) {
					$message .= "<br>ERROR: Database error updating file size: $stmts[update_size]->errno - $stmts[update_size]->error";
					return false;
				} else {
					$message .= "<br>File size successfully updated";
				}
			}
			if (empty($options['ignore_existing'])) {
				return !addImageRelationships($file, $file_id, $stmts, $options, $message);
			}
			return false;
		} elseif (false === ($size = filesize($filepath))) {
			$message .= '<br>ERROR: Failed to determine file size';
			return false;
		}
		$md5 = md5_file($filepath);
		$file_id = false;
		if (!empty($options['dry_run'])) {
			$file_id = 0; // non-existent index for dry run
		} elseif (!$stmts['add_image']->bind_param('ssiss', $dir, $file, $size, $mime, $md5) || !$stmts['add_image']->execute()) {
			$message .= "<br>ERROR: Database error adding image: $stmts[add_image]->errno - $stmts[add_image]->error";
			return false;
		} else {
			$file_id = $stmts['add_image']->insert_id;
		}
		if (!is_int($file_id)) {
			$message .="<br>ERROR: Invalid file id";
			return false;
		}
		$message .= "<br>NOTICE: File added to file manager with id $file_id";
		if (!empty($options['add_product']) || !empty($options['add_product_matrix']) || !empty($options['update_matrix'])) {
			addImageRelationships($file, $file_id, $stmts, $options, $message);
		}
		return true;
	}
	$message .= '<br>ERROR: File does not exist';
	return false;
}

/**
 *
 * @param $file    the name of the file, with or without extensions
 * @param $file_id the primary key id of the file in the CubeCart_filemanager table
 * @param $stmts   same as array of prepared statements passed to #addFile
 * @param $options same as options array passed to #addFile
 * @param $message string containing additional information
 * @return true if at least one product relationship was added
 */
function addImageRelationships($file, $file_id, array $stmts, array $options, &$message) {
	$add_product        = !empty($options['add_product']); // true to add product:image relationships
	$add_product_matrix = !empty($options['add_product_matrix']); // true to add product:image relationships for each matching matrix entry
	$update_matrix      = !empty($options['update_matrix']); // true to add matrix:image relationships
	if (!$add_product && !$add_product_matrix && !$update_matrix) {
		return false;
	}
	$code = substr_replace($file, '', strrpos($file, '.'));
	$added = 0;
	// Find matching product / matrix entries
	if ((empty($options['regexp']) && (!$stmts['find_products']->bind_param('issss', $file_id, $code, $code, $code, $code) || !$stmts['find_products']->execute())) ||
		(!empty($options['regexp']) && (!$stmts['find_products']->bind_param('isss', $file_id, $code, $code, $code) || !$stmts['find_products']->execute()))) {
		$message .= "<br>ERROR: Database error selecting matching products for $code: $stmts[find_products]->errno - $stmts[find_products]->error";
		return false;
	}
	$products = fetch_assoc_stmt($stmts['find_products'], true);
	
	// Check results when not matching by regular expression to make sure all belong to same product id
	// TODO ? check for duplicate product codes => SELECT COUNT(*) AS `count`, product_code FROM cubecart_option_matrix GROUP BY product_code HAVING `count` > 1;
	// TODO ? provide option to allow multiple products to be updated with the same image
	if (empty($options['regexp'])) {
		$product = false;
		foreach ($products as $match) {
			if (!$product) {
				$product = $match;
			} elseif ($match['product_id'] != $product['product_id']) {
				$message .= "<br>ERROR: At least two products matched the file <strong>$code</strong>:<br>Product ID: $product[product_id] - Product Code: $product[product_code]<br>Product ID: $match[product_id] - Product Code: $match[product_code]";
				return false;
			}
		}
	}
	// Build relationships for each product
	$relations = array();
	foreach ($products as $match) {
		// Check for existing relationship (cannot use INSERT IGNORE due to lack of useful unique key)
		if (array_key_exists($match['product_id'], $relations)) {
			// relationship already established on product:image level - move on to next part
		} elseif (!$stmts['relation_exists']->bind_param('ii', $match['product_id'], $file_id) || !$stmts['relation_exists']->execute()) {
			$message .= "<br>ERROR: Database error checking for existing product:image relationships: $stmts[relation_exists]->errno - $stmts[relation_exists]->error";
			return false;
		} elseif (empty($relations[$match['product_id']] = fetch_assoc_stmt($stmts['relation_exists']))) {
			// No previous product:image relationship exists for this file
			$product_match = ($match['product_code'] === $code);
			if (!$product_match) {
				if (empty($options['regexp'])) {
					// Check if the image file could be considered a 'variant' image
					$var_match = (empty($options['allow_variants']) ? '' : '(-|_)?[0-9]+');
					$suffix = (empty($options['allow_variants']) || empty($options['code_suffix']) ? '' : $options['code_suffix']);
					$regexp = "/^$match[product_code]" . (empty($suffix) ? '' : "$suffix$var_match") . "$/i";
					$product_match = preg_match($regexp, $code);
				} else {
					// Match the product code against the regexp, completely ignoring the image file's name
					$product_match = preg_match("/$options[regexp]/i", $match['product_code']);
				}
			}
			if (($product_match && $add_product) || (!empty($match['matrix_id']) && $add_product_matrix)) {
				if (!empty($options['dry_run'])) {
					$relations[$match['product_id']] = 0; // non-existent index for dry run
				} elseif (!$stmts['add_product_img']->bind_param('iis', $match['product_id'], $file_id, $options['main_image']) || !$stmts['add_product_img']->execute()) {
					$message .= "<br>ERROR: Database error adding new product:image relationship: $stmts[add_product_img]->errno - $stmts[add_product_img]->error";
					return false;
				} else {
					$relations[$match['product_id']] = $stmts['add_product_img']->insert_id;
				}
				if (!is_int($relations[$match['product_id']])) {
					$message .= "<br>ERROR: Failed to add product:image relationship - invalid insertion ID";
					return false;
				} else {
					$added++;
					$message .= "<br>NOTICE: File associated with product $match[product_id] - $match[product_code]; image index = {$relations[$match['product_id']]}";
				}
			}
		} else {
			$message .= "<br>NOTICE: File id $file_id is already associated with product $match[product_id] - $match[product_code]; image_index id = {$relations[$match['product_id']]}";
		}
		// Update matrix image entries if applicable
		if ($update_matrix && !empty($match['matrix_id'])) {
			if (!empty($match['matrix_image_file_id']) && empty($options['force_update'])) {
				$message .= "<br>WARNING: Matrix entry $match[matrix_id] - $match[matrix_product_code] is already associated with file $match[matrix_image_file_id] - $match[matrix_image_file]";
			} elseif (empty($options['dry_run']) && (!$stmts['update_matrix_img']->bind_param('ii', $file_id, $match['matrix_id']) || !$stmts['update_matrix_img']->execute())) {
				$message .= "<br>ERROR: Database error updating matrix image id: $stmts[update_matrix_img]->errno - $stmts[update_matrix_img]->error";
				return false;
			} else {
				$added++;
				if (empty($match['matrix_image_file_id'])) {
					$message .= "<br>NOTICE: Image id for matrix entry $match[matrix_id] - $match[matrix_product_code] successfully updated";
				} else {
					$message .= "<br>WARNING: Image id for matrix entry $match[matrix_id] - $match[matrix_product_code] was overwritten; previous image was $match[matrix_image_file_id] - $match[matrix_image_file]";
				}
			}
		}
	}
	$message .= "<br>NOTICE: Total new associations with this image: $added";
	return $added > 0;
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

/** This function copied from filemanager.class.php as found in CubeCart v6.0.8 */
function formatName($name) {
	return preg_replace('#[^\w\.\-\_]#i', '_', $name);
}

/**
 * @param $options uses the 'update_size', 'code_suffix', and 'regexp' values
 */
function getPreparedStatements($dbc, array $options = array()) {
	$prefix = (empty(TABLE_PREFIX) ? '' : TABLE_PREFIX) . 'CubeCart';
	$stmts = array();
	
	// prepared statement to check if image file already exists
	// params = 'sss', filepath, filepath, filename
	$q = "SELECT file_id FROM `$prefix" . "_filemanager` WHERE type=1 AND IF(? IS NULL, filepath IS NULL, filepath=?) AND filename=? LIMIT 1";
	$stmts['image_exists'] = $dbc->prepare($q);
	
	// prepared statement to insert file info into filemanager table
	// params = 'ssiss', filepath, filename, filesize, mimetype, md5hash
	$q = "INSERT INTO `$prefix" . "_filemanager` (type, disabled, filepath, filename, filesize, mimetype, md5hash, description) VALUE (1, 0, ?, ?, ?, ?, ?, '')";
	$stmts['add_image'] = $dbc->prepare($q);

	// prepared statement to check if a product:image relationship exists for the given file
	// params = 'ii', product_id, file_id
	$q = "SELECT id FROM `$prefix" . "_image_index` WHERE product_id=? AND file_id=? LIMIT 1";
	$stmts['relation_exists'] = $dbc->prepare($q);
	
	// prepared statement to add product:image entry to image_index table
	// params = 'ii', product_id, file_id
	$q = "INSERT INTO `$prefix" . "_image_index` (product_id, file_id, main_img) VALUE (?, ?, ?)";
	$stmts['add_product_img'] = $dbc->prepare($q);
	
	// prepared statement to update the option matrix image
	// params = 'ii', image_id, matrix_id
	$q = "UPDATE `$prefix" . "_option_matrix` SET image=? WHERE matrix_id=?";
	$stmts['update_matrix_img'] = $dbc->prepare($q);
	
	// prepared statement to check for products and matrix entries with codes matching the filename
	// params = 'issss', file_id, filename x 4 (x 3 if custom regexp supplied)
	$var_match = (empty($options['allow_variants']) ? '' : '(-|_)?[0-9]+');
	$suffix = (empty($options['allow_variants']) || empty($options['code_suffix']) ? '' : $options['code_suffix']);
	$q = "SELECT 
			product.product_id,
			product.product_code,
			img.id AS image_id,
			matrix.matrix_id,
			matrix.product_code AS matrix_product_code,
			matrix_img.file_id AS matrix_image_file_id,
			IF(filemanager.filepath IS NULL, filemanager.filename, CONCAT(filemanager.filepath, filemanager.filename)) AS matrix_image_file 
		FROM `$prefix" . "_inventory` product 
		LEFT JOIN `$prefix" . "_image_index` img ON img.product_id=product.product_id AND img.file_id=? 
		LEFT JOIN `$prefix" . "_option_matrix` matrix ON matrix.product_id=product.product_id 
			AND (
				# match product code: exact, code-var1, or user-defined regexp e.g. codeY(S|M|L)
				matrix.product_code=? 
				OR ? REGEXP CONCAT('^(', matrix.product_code, '" . (empty($suffix) ? ')' : "$suffix)$var_match") . "$')" . 
				(empty($options['regexp'])
					? ''
					: " OR matrix.product_code REGEXP '{$dbc->escape_string($options['regexp'])}'"
				) . "
			)
		LEFT JOIN `$prefix" . "_image_index` matrix_img ON matrix_img.id=matrix.image 
		LEFT JOIN `$prefix" . "_filemanager` filemanager ON filemanager.file_id=matrix_img.file_id 
		WHERE matrix.matrix_id IS NOT NULL OR 
			((product.product_code=? 
				OR ( # Only try matching if there is not an exact match for this product code
					matrix.matrix_id IS NULL AND 
						(" . (empty($options['regexp']) 
								? "? REGEXP CONCAT('^(', product.product_code, '" . (empty($suffix) ? ')' : "$suffix)$var_match") . "$')"
								: "product.product_code REGEXP '{$dbc->escape_string($options['regexp'])}'"
							) . "
						)
					)
				)
			)
		ORDER BY product.product_id, COALESCE(matrix.product_code, product.product_code)";
	$stmts['find_products'] = $dbc->prepare($q);
	
	// Prepared statement to update file size value of existing file entries (e.g. if image was resized)
	if (!empty($options['update_size'])) {
		$q = "UPDATE `$prefix" . "_filemanager` SET filesize=? WHERE file_id=?";
		$stmts['update_size'] = $dbc->prepare($q);
	}
	return $stmts;
}
?>
