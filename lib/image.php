<?php

	@ini_set('display_errors', 'off');

	define('DOCROOT', rtrim(realpath(dirname(__FILE__) . '/../../../'), '/'));
	define('DOMAIN', rtrim(rtrim($_SERVER['HTTP_HOST'], '/') . str_replace('/extensions/jit_image_manipulation/lib', NULL, dirname($_SERVER['PHP_SELF'])), '/'));

	##Include some parts of the engine
	require_once(DOCROOT . '/symphony/lib/boot/bundle.php');
	require_once(TOOLKIT . '/class.lang.php');
	require_once(CORE . '/class.log.php');
	require_once('class.image.php');

	define_safe('MODE_NONE', 0);
	define_safe('MODE_RESIZE', 1);
	define_safe('MODE_RESIZE_CROP', 2);
	define_safe('MODE_CROP', 3);
	define_safe('MODE_JCROP', 4);

	set_error_handler('__errorHandler');
	
	if (method_exists('Lang','load')) {
		Lang::load(LANG . '/lang.%s.php', ($settings['symphony']['lang'] ? $settings['symphony']['lang'] : 'en'));
	}
	else {
		Lang::init(LANG . '/lang.%s.php', ($settings['symphony']['lang'] ? $settings['symphony']['lang'] : 'en'));
	}
		
	function processParams($string){

		$param = (object)array(
			'mode' => 0,
			'width' => 0,
			'height' => 0,
			'position' => 0,
			'background' => 0,
			'file' => 0,
			'external' => false,
			'xpos' => 0,
			'ypos' => 0,
			'factor' => 1000
		);

		## Mode 4: JCrop
		if(preg_match_all('/^4\/([0-9]+)\/([0-9]+)\/([0-9]+)\/([0-9]+)\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 4;
			$param->crop_width = $matches[0][1];
			$param->crop_height = $matches[0][2];
			$param->xpos = $matches[0][3];
			$param->ypos = $matches[0][4];
			$param->width = $matches[0][5];
			$param->height = $matches[0][6];
			$param->external = (bool)$matches[0][7];
			$param->file = $matches[0][8];
		}

		## Mode 3: Resize Canvas
		elseif(preg_match_all('/^3\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-f0-9]{3,6})\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 3;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];
			$param->position = $matches[0][3];
			$param->background = $matches[0][4];
			$param->external = (bool)$matches[0][5];
			$param->file = $matches[0][6];
		}

		## Mode 2: Crop to fill
		elseif(preg_match_all('/^2\/([0-9]+)\/([0-9]+)\/([1-9])\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 2;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];
			$param->position = $matches[0][3];
			$param->external = (bool)$matches[0][4];
			$param->file = $matches[0][5];
		}

		## Mode 1: Image resize
		elseif(preg_match_all('/^1\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 1;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];
			$param->external = (bool)$matches[0][3];
			$param->file = $matches[0][4];
		}

		## Mode 0: Direct displaying of image
		elseif(preg_match_all('/^(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->external = (bool)$matches[0][1];
			$param->file = $matches[0][2];
		}

		return $param;
	}

	$image_param = $_GET['param'];
	
	// named rules
	if(preg_match_all('/^([a-z]+\w*)\/(.+)$/i', $_GET['param'], $matches, PREG_SET_ORDER)){
		$rule_name = $matches[0][1];
		$file = $matches[0][2];
		$named_rules = unserialize($settings['image']['named_rules']);
		
		// check for named rule
		if (is_array($named_rules[$rule_name]) && !empty($named_rules[$rule_name])) {
			$named_rule = $named_rules[$rule_name];
			$rule = $named_rule['rule'];
			$image_param = $rule . $file;
		} else {
			header('HTTP/1.0 404 Not Found');
			trigger_error(__('Named rule <code>%s</code> could not be found.', array($param->rule_name)), E_USER_ERROR);
			exit;
		}
	}
	
	$param = processParams($image_param);
	define_safe('CACHING', ($param->external == false && $settings['image']['cache'] == 1 ? true : false));

	function __errorHandler($errno=NULL, $errstr, $errfile=NULL, $errline=NULL, $errcontext=NULL){

		global $param;

		if(error_reporting() != 0 && in_array($errno, array(E_WARNING, E_USER_WARNING, E_ERROR, E_USER_ERROR))){
			$Log = new Log(ACTIVITY_LOG);

			$Log->pushToLog("{$errno} - ".strip_tags((is_object($errstr) ? $errstr->generate() : $errstr)).($errfile ? " in file {$errfile}" : '') . ($errline ? " on line {$errline}" : ''), ($errno == E_WARNING || $errno == E_USER_WARNING ? Log::WARNING : Log::ERROR), true);

/*
		stdClass Object
		(
		    [mode] => 1
		    [width] => 100
		    [height] => 210
		    [position] => 0
		    [background] => 0
		    [file] => dimages/ribbon.gif
		    [external] =>
		)
*/

			$Log->pushToLog(
				sprintf(
					'Image class param dump - mode: %d, width: %d, height: %d, position: %d, background: %d, file: %s, external: %d, raw input: %s',
					$param->mode,
					$param->width,
					$param->height,
					$param->position,
					$param->background,
					$param->file,
					(bool)$param->external,
					$_GET['param']
				), Log::NOTICE, true
			);
		}

	}

	$meta = $cache_file = NULL;

	$image_path = ($param->external === true ? "http://{$param->file}" : WORKSPACE . "/{$param->file}");
	
	if($param->external !== true){
		
		$last_modified = filemtime($image_path);
		$last_modified_gmt = gmdate('r', $last_modified);
		$etag = md5($last_modified . $image_path);
		
	    header(sprintf('ETag: "%s"', $etag));

	    if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])){
	        if($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', NULL, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag){
	            header('HTTP/1.1 304 Not Modified');
	            exit();
	        }
	    }

	    header('Last-Modified: ' . $last_modified_gmt);
	    header('Cache-Control: public');

	} 
	
	else {
		
		$rules = file(MANIFEST . '/jit-trusted-sites', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$allowed = false;

		$rules = array_map('trim', $rules);

		if(count($rules) > 0){
			foreach($rules as $r){

				$r = str_replace('http://', NULL, $r);

				if($r == '*'){
					$allowed = true;
					break;
				}

				elseif(substr($r, -1) == '*' && strncasecmp($param->file, $r, strlen($r) - 1) == 0){
					$allowed = true;
					break;
				}

				elseif(strcasecmp($r, $param->file) == 0){
					$allowed = true;
					break;
				}
			}
		}

		if($allowed == false){
			header('HTTP/1.0 404 Not Found');
			exit(__('Error: Connecting to that external site is not permitted.'));
		}

	}

	## Do cache checking stuff here
	if($param->external !== true && CACHING === true){

	    $cache_file = sprintf('%s/%s_%s', CACHE, md5($_REQUEST['param'] . $quality), basename($image_path));

		if(@is_file($cache_file) && (@filemtime($cache_file) < @filemtime($image_path))){
			unlink($cache_file);
		}

		elseif(is_file($cache_file)){
			$image_path = $cache_file;
			@touch($cache_file);
			$param->mode = MODE_NONE;
		}
	}

	####


	if($param->external !== true && $param->mode == MODE_NONE){

		if(!file_exists($image_path) || !is_readable($image_path)){
			header('HTTP/1.0 404 Not Found');
			trigger_error(__('Image <code>%s</code> could not be found.', array($image_path)), E_USER_ERROR);
		}

		$meta = Image::getMetaInformation($image_path);
		Image::renderOutputHeaders($meta->type);
		readfile($image_path);
		exit();
	}


	try{
		$method = 'load' . ($param->external === true ? 'External' : NULL);
		$image = call_user_func_array(array('Image', $method), array($image_path));
	}
	catch(Exception $e){
		header('HTTP/1.0 404 Not Found');
		trigger_error($e->getMessage(), E_USER_ERROR);
	}

	switch($param->mode){

		case MODE_RESIZE:
			$image->applyFilter('resize', array($param->width, $param->height));
			break;

		case MODE_RESIZE_CROP:

			$src_w = $image->Meta()->width;
			$src_h = $image->Meta()->height;

			$dst_w = $param->width;
			$dst_h = $param->height;

			if($param->height == 0) {
				$ratio = ($src_h / $src_w);
				$dst_w = $param->width;
				$dst_h = round($dst_w * $ratio);
			}

			elseif($param->width == 0) {

				$ratio = ($src_w / $src_h);
				$dst_h = $param->height;
				$dst_w = round($dst_h * $ratio);

			}

			$src_r = ($src_w / $src_h);
			$dst_r = ($dst_w / $dst_h);

			if($src_r < $dst_r) $image->applyFilter('resize', array($dst_w, NULL));
			else $image->applyFilter('resize', array(NULL, $dst_h));

			/*
				if($src_h < $param->height || $src_h > $param->height) ImageFilters::resize($image, NULL, $param->height);
				if($src_w < $param->width) ImageFilters::resize($image, $param->width, NULL);

			*/

		case MODE_CROP:
			$image->applyFilter('crop', array($param->width, $param->height, $param->position, $param->background));
			break;

		case MODE_JCROP:
			$image->applyFilter('jcrop', array($param->crop_width, $param->crop_height, $param->xpos, $param->ypos, $param->background));
			
			$image->applyFilter('resize', array($param->width, $param->height));

			break;
	}
	
	// check for watermark in named rule
	if (isset($named_rule['watermark'])) {
		$image->addWatermark($named_rule['watermark']);
	}

	if(!$image->display(intval($settings['image']['quality']))) trigger_error(__('Error generating image'), E_USER_ERROR);

	if(CACHING && !is_file($cache_file)){
		$image->save($cache_file, intval($settings['image']['quality']));
	}

	exit();
