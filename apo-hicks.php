<?php
/**
 * Plugin Name: APO - Hicks Integration
 * Plugin URI: https://pyrostevejr.com/
 * Description: Update products from Hicks
 * Version: 1.0.1
 * Author: Stephen Phillips
 * Author URI: https://pyrostevejr.com/
 * License: GPL2
 */

ini_set('memory_limit', '1024M');
function apo_hicks_admin_menu() {
	add_menu_page( 'APO Hicks Integration', 'APO Hicks Integration', 'manage_options', 'apo/apo-hicks.php', 'apo_hicks_admin_page', 'dashicons-cart', 4  );
}
add_action( 'admin_menu', 'apo_hicks_admin_menu' );

function apo_hicks_admin_page(){
	set_time_limit(1500);
	?>
	<div class="wrap">
		<h2>APO &amp; Hicks Product Sync</h2>
		
		<h4 style="color: #FF0000;">ONLY CLICK PROCESS IF DEVELOPER.</h4>
		
		<form autocomplete="on" role="form" action="<?php the_permalink(); ?>" method="post"  enctype="multipart/form-data" >
			<button id="process-hicks" name="process-hicks" type="submit">Process</button>
		</form>
		
		<div id="hicks-apo-data">
	<?php
	if( isset( $_POST['process-hicks'] ) ) {
		process_intake();
	} 
	?>
		</div>
	</div>
	<?php
	
	
}

// create a scheduled event (if it does not exist already)
function cronstarter_activation() {
	if( !wp_next_scheduled( 'apo_hicks_cron_job' ) ) {  
	   wp_schedule_event( time(), 'hourly', 'apo_hicks_cron_job' );  
	}
}
// and make sure it's called whenever WordPress loads
add_action('wp', 'cronstarter_activation');

// unschedule event upon plugin deactivation
function cronstarter_deactivate() {	
	// find out when the last event was scheduled
	$timestamp = wp_next_scheduled ('apo_hicks_cron_job');
	// unschedule previous event if any
	wp_unschedule_event ($timestamp, 'apo_hicks_cron_job');
} 
register_deactivation_hook (__FILE__, 'cronstarter_deactivate');

add_action ('apo_hicks_cron_job', 'process_intake'); 



function process_intake() {
	ini_set('memory_limit', '1024M');
	set_time_limit(1500);
	date_default_timezone_set("America/Chicago");
	
	
	// Email any issues
	//$to = 'pyrostevejr@gmail.com';
	$to = 'apohickslog@gmail.com';
	$subject = 'APO - Hicks - Cron Job';
	$headers = array('Content-Type: text/html; charset=UTF-8');
 
	
	$body = "<h1>APO / Hicks Sync</h1>";
		
	$starttime = microtime(true);
	
	$fileName = 'apo_hicks_' . uniqid() . '.csv';
	
	$source = "fh/full_V2.csv";
	$target = fopen($fileName, "w");
	$conn = ftp_connect("ftp.hicksinc.com") or die("Could not connect");
	ftp_login($conn,"#0035932","KB615405");
	ftp_fget($conn,$target,$source,FTP_ASCII);
	
	$fieldseparator = ",";
	$lineseparator = "\n";
	$csvfile = $fileName;
	
	echo '<br><hr>';
	
	if(!file_exists($csvfile)) {
    	echo "<p>File not found. Make sure you specified the correct path.</p>";
		$body = "<p>File not found. Make sure you specified the correct path.</p>";
		wp_mail( $to, $subject, $body, $headers );
		exit;
	}
	$file = fopen($csvfile,"r");
	if(!$file) {
    	echo "<p>Error opening data file.</p>";
		$body .= "<p>Error opening data file.</p>";
		wp_mail( $to, $subject, $body, $headers );
		exit;
	}
	$size = filesize($csvfile);
	if(!$size) {
    	echo "<p>File is empty.</p>";
		$body .= "<p>Error opening data file.</p>";
		wp_mail( $to, $subject, $body, $headers );
    	exit;
	}
	
	$body .= '<p>File Downloaded</p>';
	
	$csvcontent = fread($file,$size);
	fclose($file);
	
	$lines = 0;
	$queries = "";
	$linearray = array();
	
	global $wpdb;
	
	// 0 - Customer number
	// 1 - Item number				- ITEM / SKU
	// 2 - Last Change Date
	// 3 - Last Change Time
	// 4 - Firearm code				- USE N only
	// 5 - Status					- A active D discontuned
	// 6 - Manufacturer number
	// 7 - Vendor name
	// 8 - Short Description
	// 9 - Long Description
	// 10 - filler
	// 11 - Category
	// 12 - UPC
	// 13 - Link to image (if we have one)
	// 14 - filler
	// 15	Minimum Retail
	// 16 - MSRP (most are blank)
	// 17 - Price
	// 18 - Quantity on hand
	// 19 - Weight
	// 20 - Length
	// 21 - Width
	// 22 - Depth
	// 23 - Break pack
	// 24 - Dealer pack
	// 25 - FOB
	
	$addedArray = array();
	$updatedArray = array();
	$reAddedArray = array();
	$duplicateArray = array();
	$removedArray = array();
	
	$allLines = explode($lineseparator,$csvcontent);
	
	$hour = date("h");
	
	echo "Hour: " . $hour . "<br>";
	$body .= "Hour: " . $hour . "<br>";
	
	$numberToProcess = ceil(count($allLines) / 12);
	// Calculate start & end for batch
	if($hour == 1) {
		$startCount = 0;
		$endCount 	= $numberToProcess;
	} else {
		$startCount = $numberToProcess * ($hour - 1);
		$endCount	= $numberToProcess * $hour;
	}
	
	echo "Start Count: " . $startCount . "<br>";
	echo "End Count: " . $endCount . "<br>";
	$body .= "Start Count: " . $startCount . "<br>";
	$body .= "End Count: " . $endCount . "<br>";
	
	// Process each line
	foreach($allLines as $line) {
		$lines++;
		
    	$line = trim($line," \t");
		$line = str_replace("\r","",$line);
		//$line = str_replace("'","\'",$line);
		$line = str_replace("'","\'",$line);
		/*************************************/
		//$linearray = explode($fieldseparator,$line);
		$linearray = str_getcsv($line, ",", "\"");
		
		// Check for blank end line
		if( count( $linearray) > 1) {
			$is_fire_arm 	= str_replace('"', "",$linearray[4]);
			$status 		= str_replace('"', "",$linearray[5]);
            $category 		= trim(str_replace( '"','',$linearray[11] ));
			
			// Check if Firearm - Do not process
			if($is_fire_arm == "N" &&
               $category != 'AMMUNITION' &&
               $category != 'AIR RIFLES' &&
               $category != 'BLACK POWDER' &&
               $category != 'MAGAZINES' &&
               $category != 'SHOOTING'
               ) {
				
				//if($lines > 9790 && $lines <= 9800) {	
				if($lines >= $startCount && $lines <= $endCount ) {
				
					// Check if active
					if($status == "A") {
					
						// Check if exsists
						$sku = trim(str_replace( '"','',$linearray[1] ));
						$found = $wpdb->get_results( "SELECT * FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '" . $sku . "'" );
					
						if(count($found) == 1) {
							// UPDATE
							$update_id = 0;
							foreach ( $found as $foundItem ) {
								$update_id = $foundItem->post_id;
							}
							$currentStatus = get_post_status($update_id);
							if($currentStatus == 'publish') {
								$updateProduct = createProduct($linearray, true, $update_id, $lines);
								$updatedArray[] = $updateProduct;
							} else {
								$updateProduct = createProduct($linearray, true, $update_id, $lines);
								$reAddedArray[] = $updateProduct;
							}
						} else if(count($found) > 1) {
							// DONT UPDATE - ISSUE
							foreach ( $found as $foundItem ) {
								$update_id = $foundItem->post_id;
								$duplicateArray[] = $update_id;
							}
						} else if(count($found) == 0) {
							$newProductID = createProduct($linearray, false, null, $lines);
							$addedArray[] = $newProductID;
						}
					} else {
						// Don't add or update if changed
						$sku = trim(str_replace( '"','',$linearray[1] ));
						$found = $wpdb->get_results( "SELECT * FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = '" . $sku . "'" );
						
						if(count($found) == 1) {
							// UPDATE
							$remove_id = 0;
							foreach ( $found as $foundItem ) {
								$remove_id = $foundItem->post_id;
							}
							$currentStatus = get_post_status($remove_id);
							// REMOVE IF PUBLISHED
							if($currentStatus == 'publish') {
								$removeProduct = createProduct($linearray, true, $remove_id, $lines);
								$removedArray[] = $removeProduct;
							}
						}
					}
					echo '<hr>';
				
				} // if line check
			} else {
				// Do not add
			}
		} else {
			$lines--;
		}
	}

	$endtime = microtime(true);
	$timediff = $endtime - $starttime;
	
	echo '<p>Elapsed Time: ' . secondsToTime($timediff) . '<p>';
	echo '<hr>';
	echo '<p>Found a total of ' . $lines . ' records in the csv file.<p>';
	echo '<p>Products added: ' . count($addedArray) . '</p>';
	echo '<p>Products re-added: ' . count($reAddedArray) . '</p>';
	echo '<p>Products updated: ' . count($updatedArray) . '</p>';
	echo '<p>Products removed: ' . count($removedArray) . '</p>';
	echo '<p>Duplicates: ' . count($duplicateArray) . '</p>';
	
	$body .= '<p>Elapsed Time: ' . secondsToTime($timediff) . '<p>';
	$body .= '<hr>';
	$body .= '<p>Found a total of ' . $lines . ' records in the csv file.<p>';
	$body .= '<p>Products added: ' . count($addedArray) . '</p>';
	$body .= '<p>Products re-added: ' . count($reAddedArray) . '</p>';
	$body .= '<p>Products updated: ' . count($updatedArray) . '</p>';
	$body .= '<p>Products removed: ' . count($removedArray) . '</p>';
	$body .= '<p>Duplicates: ' . count($duplicateArray) . '</p>';
	
	// Delete file when done.
	unlink($csvfile);
	
	if(count($addedArray) > 0) {
		echo '<hr>';
		$body .= '<hr>';
		echo '<h4>Products Added</h4>';
		$body .= '<h4>Products Added</h4>';
		foreach($addedArray as $item) {
			echo 'ID: ' . $item . '<br>';
			$body .= 'ID: ' . $item . '<br>';
		}
	}
	
	if(count($reAddedArray) > 0) {
		echo '<hr>';
		$body .= '<hr>';
		echo '<h4>Products Re-Added</h4>';
		$body .= '<h4>Products Re-Added</h4>';
		foreach($reAddedArray as $item) {
			echo 'ID: ' . $item . '<br>';
			$body .= 'ID: ' . $item . '<br>';
		}
	}
	
	if(count($duplicateArray) > 0) {
		echo '<hr>';
		$body .= '<hr>';
		echo '<h4>Duplicate Products</h4>';
		$body .= '<h4>Duplicate Products</h4>';
		foreach($duplicateArray as $item) {
			echo 'ID: ' . $item . '<br>';
			$body .= 'ID: ' . $item . '<br>';
		}
	}
	
	if(count($updatedArray) > 0) {
		echo '<hr>';
		$body .= '<hr>';
		echo '<h4>Products Updated</h4>';
		$body .= '<h4>Products Updated</h4>';
		foreach($updatedArray as $item) {
			echo 'ID: ' . $item . '<br>';
			$body .= 'ID: ' . $item . '<br>';
		}
	}
	
	if(count($removedArray) > 0) {
		echo '<hr>';
		$body .= '<hr>';
		echo '<h4>Products Removed</h4>';
		$body .= '<h4>Products Removed</h4>';
		foreach($removedArray as $item) {
			echo 'ID: ' . $item . '<br>';
			$body .= 'ID: ' . $item . '<br>';
		}
	}
	
	wp_mail( $to, $subject, $body, $headers );
	
}

function secondsToTime($s) {
    $h = floor($s / 3600);
    $s -= $h * 3600;
    $m = floor($s / 60);
    $s -= $m * 60;
    return $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
}


function createProduct($linearray,$update,$update_id,$lines) {
	
	$name 			= ucwords(strtolower(trim($linearray[8] )));
	$description 	= ucfirst(strtolower(trim($linearray[9] )));
	$status 		= str_replace('"', "",$linearray[5]);
	// This removes encoded character like black diamond question mark
	$description	= preg_replace('/[\x00-\x1F\x7F-\xFF]/', ' ', $description);
	$category 		= trim(str_replace( '"','',$linearray[11] ));
	$image 			= trim(str_replace( '"','',$linearray[13] ));
	$price 			= $linearray[17];
	//$price			= number_format($price * 1.3, 2);
	$price			= number_format($price * 1.3, 2, '.', '');
	$quantity 		= $linearray[18];
	$weight 		= $linearray[19];
	$length 		= $linearray[20];
	$width 			= $linearray[21];
	$height 		= $linearray[22];
	$sku 			= trim(str_replace( '"','',$linearray[1] ));
	
		
	if($update == true) {
		// Update Product
		$post_status = 'publish';
		if($status == 'D') {
			$post_status = 'draft';
		}
		$args = array(	   
			'ID' 			=> $update_id,
			'post_status' 	=> $post_status
		);
		$post_id = wp_update_post( $args );
		
		//processCategory($post_id,$category);
		
	} else {
		// Add Product
		$args = array(	   
			'post_author' => 1, 
			'post_content' => $description,
			'post_status' => "publish", // (Draft | Pending | Publish)
			'post_title' => $name,
			'post_type' => "product"
		); 
		$post_id = wp_insert_post( $args );
		
		// Setting the product type
		wp_set_object_terms( $post_id, 'simple', 'product_type' );
		processCategory($post_id,$category);
	}
	
	if($post_id != 0) {
		// Setting the product price
		update_post_meta( $post_id, '_regular_price', $price );
		update_post_meta( $post_id, '_sale_price', '' );
		update_post_meta( $post_id, '_weight', $weight );
		update_post_meta( $post_id, '_length', $length );
		update_post_meta( $post_id, '_width', $weight );
		update_post_meta( $post_id, '_height', $height );
		update_post_meta( $post_id, '_sku', $sku );
		update_post_meta( $post_id, '_product_attributes', array() );
		update_post_meta( $post_id, '_sale_price_dates_from', '' );
		update_post_meta( $post_id, '_sale_price_dates_to', '' );
		update_post_meta( $post_id, '_price', $price );
		update_post_meta( $post_id, '_sold_individually', 'no' );
		update_post_meta( $post_id, '_manage_stock', 'yes' );
		update_post_meta( $post_id, '_backorders', 'no' );
		update_post_meta( $post_id, '_stock', $quantity );
        
        if($update == false) {
            update_post_meta( $post_id, '_stock_status', 'instock');
            update_post_meta( $post_id, '_visibility', 'visible' );
            update_post_meta( $post_id, 'total_sales', '0' );
		    update_post_meta( $post_id, '_downloadable', 'no' );
            update_post_meta( $post_id, '_virtual', 'no' );
            update_post_meta( $post_id, '_featured', 'no' );
            update_post_meta( $post_id, 'fifu_image_url', $image);
        }
	} 
	
	return $post_id . " - " . $sku . " - " . $name . " - Price: " . $price . " - Stock: " . $quantity . " - Line Entry: " . $lines;
}

function processCategory($post_id, $category) {
	
	$foundCategory = false;
	
	switch ($category) {
		case 'ACCESSORIES':
			$cat_ids = array( 1380, 1381 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
        case 'ARCHERY':
			$cat_ids = array( 1380, 59 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;                       
		/*case 'AIR RIFLES':
			$cat_ids = array( 1380, 71 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;*/
		/*case 'AMMUNITION':
			$cat_ids = array( 1380, 53 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;*/
		/*case 'BLACK POWDER':
			$cat_ids = array( 1380, 70 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;*/
		case 'CAMPING':
			$cat_ids = array( 1380, 108 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'Class-97':
        	break;
		case 'FLASHLIGHTS':
			$cat_ids = array( 1380, 84, 87 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'FLAG POLES USA':
			$cat_ids = array( 1380, 1381, 1404 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'FLOATS':
			$cat_ids = array( 1380, 1383, 1384 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HATS':
			$cat_ids = array( 1380, 55, 1385 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HICKS IMPORTED POLES':
			$cat_ids = array( 1380, 1383, 1386 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HICKS IMPORTED RODS':
			$cat_ids = array( 1380, 1383, 1403 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HOOKS':
			$cat_ids = array( 1380, 1383, 1387 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HUNTING ACCESSORIES':
			$cat_ids = array( 1380, 96, 1397 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'HUNTING CLOTHES':
			$cat_ids = array( 1380, 55, 1398 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'KNIVES':
			$cat_ids = array( 1380, 89, 1385 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'LEAD':
			$cat_ids = array( 1380, 1383, 1388 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'LINE':
			$cat_ids = array( 1380, 1383, 1389 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'LURES':
			$cat_ids = array( 1380, 1383, 1390 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		/*case 'MAGAZINES':
			$cat_ids = array( 1380, 63, 1399 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;*/
		case 'MARINE':
			$cat_ids = array( 1380, 1382 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'POLES':
			$cat_ids = array( 1380, 1383, 1391 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'REEL PARTS':
			$cat_ids = array( 1380, 1383, 1393 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'REELS':
			$cat_ids = array( 1380, 1383, 1392 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'RELOADING':
			$cat_ids = array( 1380, 72 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'RODS':
			$cat_ids = array( 1380, 1383, 1394 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'SCOPES':
			$cat_ids = array( 1380, 79, 80 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'SPORTING GOODS':
			$cat_ids = array( 1380, 1405 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
		case 'SWIVELS, LEADERS, RIGS':
			$cat_ids = array( 1380, 1383, 1395 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'TACKLE BOXES & NETS':
			$cat_ids = array( 1380, 1383, 1400 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'TACTICAL ACCESSORIES':
			$cat_ids = array( 1380, 1401 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		case 'TOPS & GUIDES':
			$cat_ids = array( 1380, 1383, 1396 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
			$foundCategory = true;
        	break;
		default:
			$cat_ids = array( 1380, 1402 );
			wp_set_object_terms( $post_id, $cat_ids, 'product_cat' );
        
	}	
	return $foundCategory;
}