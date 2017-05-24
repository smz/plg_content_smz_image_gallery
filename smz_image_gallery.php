<?php
/**
 * @package		SMZ Image Gallery (plugin)
 * @author		Sergio Manzi - http://smz.it
 * @copyright	Copyright (c) 2013 - 2016 Sergio Manzi. All rights reserved.
 * @license		GNU General Public License version 3 or (at your option) any later version.
 * @version		3.6.0
 */

defined('_JEXEC') or die;

class PlgContentSmz_image_gallery extends JPlugin {

	// Syntax:	{gallery}folder_path:thumbs_width:thumbs_height:display_mode:caption_mode:popup_engine:gallery_layout:suppress_errors:sort_order{/gallery}
	//
	//		src=			0: folder_path:
	//							folder containing the gallery images. Path is relative to the "Galleries root folder" parameter set in the backend
	//
	//		tw=			1: thumbs_width:
	//							Thumbnails width (in pixels)
	//
	//		th=			2: thumbs_height:
	//							Thumbnails height (in pixels)
	//
	//		dm=			3: display_mode:
	//		display=		0, 1 = normal display mode,
	//						2 = use Masonry Javascript to rearrange images
	//
	//		n.a.			4: caption_mode:
	//							ignored
	//
	//		fb=			5: popup_engine (lightbox):
	//		fancybox=		0 or none = no lightbox,
	//		lightbox=		1 or jquery_fancybox = use Fancybox with images grouped by gallery id
	//							any other string = use Fancybox, with images grouped by that string
	//
	//		tp=			6: gallery_layout:
	//		tpl=				classic ("Polaroid" style) or,
	//		template= 		simple (Plain images)
	//		layout=
	//
	//		el=			7: suppress_errors
	//		errorlevel=		0 = No
	//							1 = Only "Folder not found" error
	//							2 = Most errors
	//
	//		sort=			8: sort_order
	//		order=			A = ascending
	//							D = descending
	//
	//		gutter=		9:	gutter:
	//		gt=				the spacing (in pixels) between adjacent cells
	//		margin=
	//		mg=


	// Class variables
	private $pluginName = 'smz_image_gallery';
	private $pluginTag = 'gallery';
	private $masonryRev = '4.0.0';
	private $cacheFilenameLength = 12;
	private $galleryIdLength = 8;
	private $syntax = array (
		'galleryFolder' => array('src', '0'),
		'thb_width' => array('tw', '1'),
		'thb_height' => array('th', '2'),
		'display_mode' => array('dm', 'display', '3'),
		'caption_mode' => array('4'), // Unused ATM...
		'use_fancybox' => array('fb', 'fancybox', 'lightbox', '5'),
		'layout' => array('tp', 'tpl', 'template', 'layout', '6'),
		'suppress_errors' => array('el', 'errorlevel', '7'),
		'sort_order' => array('sort', 'order', '8'),
		'gutter' => array('gutter', 'gt', 'margin', 'mg', '9')
		);

	private $bailOut = false;
	private $options;
	private $app;
	private $lexicon;
	private $gallery = array();

	function __construct(&$subject, $params)
	{
		// Setup parent class
		parent::__construct($subject, $params);

		$this->app = JFactory::getApplication();

		// Do not run in Admin mode
		if ($this->app->isAdmin())
		{
			$this->bailOut = true;
			return;
		}

		// Setup the options object
		$this->options = new stdClass;

		// autoGallery is the only option we need at this time.
		$this->options->autoGallery = $this->params->get('autoGallery', 0);
	}


	function init()
	{
		// Do not continue if something was wrong already
		if ($this->bailOut)
		{
			return;
		}

		// Load the plugin language file
		JPlugin::loadLanguage('plg_content_' . $this->pluginName);

		// Bail out if the page format is not what we want
		if (!in_array($this->app->input->getCmd('format', ''), array('', 'html', 'feed', 'json')))
		{
			$this->bailOut = true;
			return;
		}

		// Check we can use the gd extension
		if (!extension_loaded('gd') && !function_exists('gd_info'))
		{
			$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_NOGD'), 'error');
			$this->bailOut = true;
			return;
		}

		// Set-up the cache folder
		if (!is_writable(JPATH_SITE . '/cache'))
		{
			$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_CACHE'), 'error');
			$this->bailOut = true;
			return;
		}
		$this->cache_folder = JPATH_SITE . '/cache/' . $this->pluginName;
		$this->cacheURL = JUri::base(true) . '/cache/' . $this->pluginName .'/';
		if (!file_exists($this->cache_folder))
		{
			if (!mkdir($this->cache_folder, 0755))
			{
				$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_CACHE'), 'error');
				$this->bailOut = true;
				return;
			}
		}
		if (!is_writable($this->cache_folder))
		{
			$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_CACHE'), 'error');
			$this->bailOut = true;
			return;
		}


		// Initialize global options
		$this->options->galleries_rootfolder = trim($this->params->get('galleries_rootfolder', '/images'), " \t\n\r\0\x0B/.\\");
		$this->options->autoGalleryFolder = trim($this->params->get('autoGalleryFolder', 'gallery'), " \t\n\r\0\x0B/.\\");
		$this->options->fancybox_grouping = $this->params->get('fancybox_grouping', 'data-fancybox-group');
		$this->options->load_masonry = $this->params->get('load_masonry', 1);
		$this->options->info_file = trim($this->params->get('info_file', 'titles.txt')," \t\n\r\0\x0B/.\\");
		$this->options->sidecar_files_extension = '.' . trim($this->params->get('sidecar_files_extension', 'txt')," \t\n\r\0\x0B/.");
		$this->options->title_field = trim($this->params->get('title_field', 'title'));
		$this->options->name_value_separator = substr(trim($this->params->get('name_value_separator', ':')), 0, 1);
		$this->options->thumbs_only_field_flag = substr(trim($this->params->get('thumbs_only_field_flag', '#')), 0, 1);
		$this->options->lightbox_only_field_flag = substr(trim($this->params->get('lightbox_only_field_flag', '@')), 0, 1);
		$this->options->cache_time = (int)$this->params->get('cache_time', 0) * 60;
		$this->options->jpg_quality = (int)$this->params->get('jpg_quality', 80);
		$this->options->memoryLimit = (int)$this->params->get('memoryLimit', 0);
		$this->options->recurse = false;

		// Try to honor the memoryLimit option
		if ($this->options->memoryLimit > 0)
		{
			ini_set('memory_limit', $this->options->memoryLimit . 'M');
		}

		// Flip the "syntax" array into the "lexicon" array.
		foreach ($this->syntax as $command => $aliases)
		{
			foreach ($aliases as $alias)
			{
				$this->lexicon[$alias] = $command;
			}
		}
	}


	function onContentAfterDisplay($context, &$row, &$params, $page = 0)
	{
		// Do not continue if something was wrong already
		if ($this->bailOut)
		{
			return;
		}
		// Bail out if the autoGallery option is not set or the page is not what we want
		if (!$this->options->autoGallery ||
			$context != 'com_content.article' ||
			!$row->id > 0)
		{
			return;
		}

		$this->init();

		if ($this->bailOut)
		{
			return;
		}

		jimport('joomla.filesystem.folder');

		// Get the article category path
		$categories = JCategories::getInstance('Content');
		$category = $categories->get($row->catid);
		do
		{
			$catlist[] = $category->alias;
			$category = $category->getParent();
		} while ($category->alias != 'root');
		$categoryPath = implode('/', array_reverse($catlist));

		// Build the folder path from the category path + article alias + sub-folder
		$this->options->galleryFolder =  $categoryPath . '/' . $row->alias . '/' . $this->options->autoGalleryFolder;

		// We MUST return here if the folder does not exist or we would get an error from setOptions()!
		if (!JFolder::exists(JPATH_SITE . '/' . $this->options->galleries_rootfolder . '/' . $this->options->galleryFolder))
		{
			return;
		}

		$this->buildGallery('');

		// Nothing in this gallery, just return
		if (empty($this->gallery))
		{
			return;
		}

		return $this->renderGallery();
	}

	// onContentPrepare handler
	function onContentPrepare($context, &$row, &$params, $page = 0)
	{
		// Do not continue if something was wrong already
		if ($this->bailOut)
		{
			return;
		}

		// Bail out if the page is not what we want
		if ($context != 'com_content.article' ||
			!$row->id > 0)
		{
			return;
		}
		// Simple performant check to determine whether plugin should process further
		if (JString::strpos($row->text, $this->pluginTag) === false)
		{
			return;
		}


		$this->init();

		if ($this->bailOut)
		{
			return;
		}

		jimport('joomla.filesystem.folder');

		// expression to search for
		$regex = "#{" . $this->pluginTag . "}(.*?){/" . $this->pluginTag . "}#is";

		// Find all instances of the plugin and put them in $matches
		preg_match_all($regex, $row->text, $matches);

		// Number of plugins
		$count = count($matches[0]);

		// Plugin only processes if there are any instances of the plugin in the text
		if (!$count) return;


		// When used with K2 extra fields
		if (!isset($row->title))
		{
			$row->title = '';
		}

		// Process plugin tags
		if (preg_match_all($regex, $row->text, $matches, PREG_PATTERN_ORDER) > 0)
		{

			// start the replace loop
			foreach ($matches[0] as $key => $match)
			{

				// Get the plugin tag string
				$tagcontent = preg_replace('/{.+?}/', '', $match);

				// Render the gallery
				$this->buildGallery($tagcontent);

				// Nothing in this gallery, silently continue with the next one
				if (empty($this->gallery))
				{
					continue;
				}

				// Render the gallery
				$plg_html = $this->renderGallery();

				// Do the replace
				$row->text = preg_replace('#{' . $this->pluginTag . '}' . str_replace('\\', '\\\\', $tagcontent) . '{/' . $this->pluginTag . '}#s', $plg_html, $row->text);

				// Unset the gallery
				unset($this->gallery);
			}
		}
	}


	/* ------------------ Options Parsing Functions ------------------ */


	function setOptions($tagcontent)
	{

		// Initialize overridable options with plugin default values
		$this->options->thb_width = (int)$this->params->get('thb_width', 220);
		$this->options->thb_height = (int)$this->params->get('thb_height', 220);
		$this->options->display_mode = $this->params->get('display_mode', 0);
//		$this->options->caption_mode = $this->params->get('caption_mode', 0);  // Unused ATM...
		$this->options->use_fancybox = $this->params->get('use_fancybox', 1);
		$this->options->layout = trim($this->params->get('layout', 'classic'));
		$this->options->suppress_errors = $this->params->get('suppress_errors', 0);
		$this->options->sort_order = $this->params->get('sort_order', 'A');
		$this->options->gutter = (int)trim($this->params->get('gutter', 10));

		$this->options->lightbox = '';
		$this->options->fancybox_group = '';

		$this->pageURL = false;
		$this->masonry_options = '';

		// We are optimists
		$ok = true;

		$tagcontent = trim($tagcontent);

		// Get parameters from the plugin tag string
		if (!empty($tagcontent))
		{
			if (strpos($tagcontent,'=') === false && strpos($tagcontent,':') === false )
			{
				// No parameters, just the gallery folder.
				$this->options->galleryFolder = $tagcontent;
			}
			elseif (strpos($tagcontent,'=') !== false)  // New style (param=value)
			{
				$this->parseOptions($tagcontent, $this->lexicon);
			}
			else	// Old style (param0:param1:param2:...)
			{
				$this->parseOldOptions($tagcontent, $this->lexicon);
			}
		}


		// Check/fix options values

		// galleryFolder
		if (!isset($this->options->galleryFolder))
		{
			$this->options->galleryFolder = '';
		}

		$this->options->galleryFolder = trim($this->options->galleryFolder, " \t\n\r\0\x0B/.\\");
		if ($this->options->galleryFolder == '')
		{
			$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_NO_GALLERY_FOLDER', $tagcontent), 'error');
			return false;
		}

		$this->options->galleryFolder = $this->options->galleries_rootfolder . '/' . $this->options->galleryFolder;
		if (!JFolder::exists(JPATH_SITE . '/' . $this->options->galleryFolder))
		{
			if ($this->options->suppress_errors < 1)
			{
				$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_GALLERY_FOLDER', $this->options->galleryFolder), 'error');
			}
			$ok = false;
		}

		// Set the gallery ID from the source folder hash
		$this->gallery_id = substr(md5($this->options->galleryFolder), 0, $this->galleryIdLength);


		// thb_width
		$this->options->thb_width = (int)$this->options->thb_width;

		// thb_heigth
		$this->options->thb_height = (int)$this->options->thb_height;

		// display_mode
		switch ($this->options->display_mode)
		{
			case '0':
			case '1':
			case 'normal':
				$this->options->display_mode = 0;
				$this->options->margin_right = $this->options->gutter;
				$this->options->margin_bottom = $this->options->gutter;
				break;
			case '2':
			case 'masonry':
				$this->options->display_mode = 2;
				$this->options->margin_right = 0;
				$this->options->margin_bottom = $this->options->gutter;
				$this->masonry_options = " data-masonry='{\"itemSelector\":\".sigCell\", \"gutter\":{$this->options->gutter}}'";
				break;
			default:
				$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_DISPLAY_MODE', $this->options->display_mode), 'error');
				$ok = false;
		}

		// use_fancybox
		switch ($this->options->use_fancybox)
		{
			case 'jquery_fancybox':
			case '1';
			case 'yes';
			case 'true';
				$this->options->fancybox_group = 'sig-' . $this->gallery_id;
				$this->options->lightbox = ' fancybox';
				$this->options->use_fancybox = true;
				break;
			case 'none':
			case '0':
			case 'no';
			case 'false';
				$this->options->lightbox = '';
				$this->options->use_fancybox = false;
				break;
			default:
				$this->options->fancybox_group = htmlspecialchars($this->options->use_fancybox, ENT_QUOTES | ENT_HTML5);
				$this->options->lightbox = ' fancybox';
				$this->options->use_fancybox = true;
		}

		// layout
		switch ($this->options->layout)
		{
			case 'classic':
			case 'slides':
				break;
			case 'simple':
				if ($this->options->display_mode == 2)
				{
					$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_SIMPLE_AND_MASONRY'), 'error');
					$ok = false;
				}
				break;
			default:
				$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_LAYOUT', $this->options->layout), 'error');

		}

		// suppress_errors
		switch ($this->options->suppress_errors)
		{
			case '0':
			case '1':
			case '2':
				$this->options->suppress_errors = (int) $this->options->suppress_errors;
				break;
			default:
				$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_SUPPRESS_ERRORS', $this->options->suppress_errors), 'error');
				$ok = false;
		}

		// sort_order
		switch ($this->options->sort_order)
		{
			case 'A':
			case 'D':
				break;
			case 'd':
			case 'a':
				$this->options->sort_order = strtoupper($this->options->sort_order);
				break;
			default:
				$this->app->enqueueMessage(JText::sprintf('PLG_SMZ_SIG_ERR_SORT_ORDER', $this->options->sort_order), 'error');
				$ok = false;
		}

		// Message to show when printing an article/item with a gallery
		$this->pageURL = JUri::GetInstance()->toString();

		return $ok;
	}


	// Parse "command line" options (New style)
	function parseOptions($optionsString)
	{
		$out = array();

		$options = explode(',', $optionsString);			// Explodes options string into an array of "param=value" strings
		foreach ($options as $param_pair) 					// Then for each of them...
		{
			$pair = explode('=', $param_pair); 				// Explode into an (param, value) array

			if (count($pair) != 2)								// If we don't have a pair=value pair, then it was a malformed string, something like "=aaa", "aaa=" or "aaa=bbb=ccc"
			{
				continue;											// Best we can do is just to ignore it. (Raise an error?)
			}

			foreach ($pair as $i => $e)						// For each of them (tipically 2...)
			{
				$v = trim($e);										// Trim it
				if ($v == '')										// If empty after trimming, unset it, so then we can skip
				{
					unset($pair[$i]);
				}
				else
				{
					$pair[$i] = $v;
				}
			}

			if (isset($pair[0]) && isset($pair[1]))		// It can happen that we have two elements, but not on index 0 and 1: eg. with parm==value
			{
				if (array_key_exists($pair[0], $this->lexicon))	// Is this a valid option/alias?
				{
					$prop = $this->lexicon[$pair[0]];
					$this->options->$prop = $pair[1];		// Good, set it!
				}
//				else
//				{
//					;													// Unrecognized option/alias, ignore it. (Raise an error?)
//				}
			}
//			else
//			{
//				;														// Syntax error (eg.: parm==value, ignore it). (Raise an error?)
//			}
		}

		return $out;
	}


	// Parse "command line" options (Old style)
	function parseOldOptions($optionsString)
	{
		$out = array();

		$values = explode(':', $optionsString);			// The old way...

		for ($i=0; $i<10; $i++)
		{
			if (isset($values[$i]) && !empty($values[$i]))
			{
				$prop = $this->lexicon[(string)$i];
				$this->options->$prop = $values[$i];
			}
//			else
//			{
//				;														// We don't have it, we don't set it...
//			}
		}

		return $out;
	}


	/* ------------------ Rendering Function ------------------ */


	// Render the gallery
	function buildGallery($tagcontent)
	{
		// get/set/fix options for current gallery. Just return if there were errors
		if (!$this->setOptions($tagcontent))
		{
			return;
		}

		jimport('joomla.filesystem.folder');

		// Path assignment
		$sitePath = JPATH_SITE . '/';

		$srcFolder = JFolder::files($sitePath . $this->options->galleryFolder, '.', $this->options->recurse, true);

		// Proceed if the folder is OK or fail silently
		if (!$srcFolder) return;

		// Array of valid file types
		$fileTypes = array('jpg', 'jpeg', 'gif', 'png');

		// Loop through the source folder searching for images and create an array of matching files
		$found = array();
		foreach ($srcFolder as $srcImage)
		{
			$fileInfo = pathinfo($srcImage);
			if (array_key_exists('extension', $fileInfo) && in_array(strtolower($fileInfo['extension']), $fileTypes))
			{
				$found[] = $srcImage;
			}
		}

		// Bail out if there are no images
		if (count($found) == 0)	return;

		// Sort array
		if ($this->options->sort_order == 'D')
		{
			rsort($found);
		}
		else
		{
			sort($found);
		}

		// Loop through the image file list
		foreach ($found as $key => $filename)
		{
			// Object to hold each image elements
			$this->gallery[$key] = (object) array();

			// Assign source image and path to a variable
			$original = substr($filename,strlen($sitePath));

			// Unique cache file name for every thumbnail at every size. Thumbnails are always JPEG images.
			$thumbfilename = substr(md5($original . 'w' . $this->options->thb_width . 'h' . $this->options->thb_height), 0, $this->cacheFilenameLength) . '.jpg';

			// Check if thumb image exists already and is current
			$thumbfile = $this->cache_folder . '/' . $thumbfilename;

			if (file_exists($thumbfile) && is_readable($thumbfile))
			{
				$thumb_mtime = filemtime($thumbfile);
				if ((filemtime($original) > $thumb_mtime)	|| ($this->options->cache_time > 0 && time() > ($thumb_mtime + $this->options->cache_time)))
				{
					$refresh_cache = true;
				}
				else
				{
					$refresh_cache = false;
				}
			}
			else
			{
				$refresh_cache = true;
			}

			// Otherwise create the thumb image
			if ($refresh_cache)
			{

				// begin by getting the details of the original
				list($original_width, $original_height, $type) = getimagesize($original);

				// calculate thumbnail size
				$thumb_size = $this->getThumbsSize($original_width, $original_height, $this->options->thb_width, $this->options->thb_height);
				$thumb_width = $thumb_size['width'];
				$thumb_height = $thumb_size['height'];

				// create an image resource for the original
				switch ($type)
				{
					case 1 :
						$source = @ imagecreatefromgif($original);
						if (!$source && $this->options->suppress_errors < 2)
						{
							$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_GIFS'), 'error');
							return;
						}
						break;
					case 2 :
						$source = imagecreatefromjpeg($original);
						break;
					case 3 :
						$temp = imagecreatefrompng($original);
						$source = imagecreatetruecolor($original_width, $original_height);
						// create white background for transparency
						imagefill($source, 0, 0, imagecolorallocate($source,  255, 255, 255));
						imagecopy($source, $temp, 0, 0, 0, 0, $original_width, $original_height);
						imagedestroy($temp);
						break;
					default :
						$source = null;
				}

				// Bail out if the image resource is not OK
				if (!$source && $this->options->suppress_errors < 2)
				{
					$this->app->enqueueMessage(JText::_('PLG_SMZ_SIG_ERR_SRC_IMGS'), 'error');
					return;
				}

				// create an image resource for the thumbnail
				$thumb = imagecreatetruecolor($thumb_width, $thumb_height);

				// create the resized copy
				imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumb_width, $thumb_height, $original_width, $original_height);

				// convert and save all thumbs to .jpg
				$success = imagejpeg($thumb, $thumbfile, $this->options->jpg_quality);

				// Bail out if there is a problem in the GD conversion
				if (!$success) return;

				// remove the image resources from memory
				imagedestroy($source);
				imagedestroy($thumb);

			}
			else
			{
				$imagesize = getimagesize($thumbfile);
				$thumb_width = $imagesize[0];
				$thumb_height = $imagesize[1];
			}

			$filename = mb_substr($original, strrpos($original,'/')+1);
			$title = substr($filename, 0, strlen($filename) - strlen(strrchr($filename, '.'))); // remove suffix
			$title = str_replace('_', ' ', $title); 															// replace underlines with spaces
//			$title = preg_replace ('/[0-9-\s]*(.*)/', '$1', $title); 									// remove first numbering prefix (nnnn - )

			// Assemble the image elements
			$this->gallery[$key]->filename = $filename;
			$this->gallery[$key]->sourceImageURL = '/' . str_replace(array(' ', '\\'), array('%20', '/'), $original);
			$this->gallery[$key]->thumbImageURL = $this->cacheURL . $thumbfilename;
			$this->gallery[$key]->width = $thumb_width;
			$this->gallery[$key]->height = $thumb_height;
			$this->gallery[$key]->title = htmlentities($title, ENT_QUOTES);
			$this->gallery[$key]->info = new stdClass();

			// Read image info from sidecar file
			$this->getInfoFromSidecar($original, $key);
		}

		// Read images info from the "directory info" file...
		$this->getInfoFromInfofile();

		return;
	}


	function renderGallery()
	{
		// Load CSS file
		JHtml::stylesheet('plg_smz_image_gallery/' . $this->options->layout . '.css', array(), true);

		// CSS & JS includes: Append head includes, but not when we're outputing raw content (like in K2)
		if ($this->app->input->getCmd('format') == '' || $this->app->input->getCmd('format') == 'html')
		{
			// Setup masonry if required
			if ($this->options->load_masonry && $this->options->display_mode == 2)
			{
				JHtml::_('jquery.framework');
				JHtml::script("plg_smz_image_gallery/masonry.{$this->masonryRev}.min.js", false, true, false );
				$this->options->load_masonry = false; // we want to load it just once
			}
		}

		// Get the layout template
		$template = $this->app->getTemplate();
		$overridden_layout = JPATH_SITE . "/templates/{$template}/html/{$this->pluginName}/{$this->options->layout}.php";
		if (file_exists($overridden_layout))
		{
			$layout = $overridden_layout;
		}
		else
		{
			$layout = JPATH_SITE . "/plugins/content/{$this->pluginName}/tmpl/{$this->options->layout}.php";
		}

		// Here we go...
		ob_start();
		include $layout;

		return ob_get_clean();
	}


	/* ------------------ Helper Functions ------------------ */


	// Calculate thumbnail dimensions
	function getThumbsSize($original_width, $original_height, $container_width, $container_height)
	{
		$original_ratio = $original_width / $original_height;
		$container_ratio = $container_width / $container_height;

		if ($container_ratio > $original_ratio)
		{
			$thumb_width = $container_height * $original_ratio;
			$thumb_height = $container_height;
		}
		else
		{
			$thumb_width = $container_width;
			$thumb_height = $container_width / $original_ratio;
		}

		return array('width' => round($thumb_width), 'height' => round($thumb_height));
	}


	// Read a "clean" line from file
	function readLine($handle, $trim=false, $strip_tags=false)
	{
		$ln = false;

		if ($handle)
		{
			if (($ln = fgets($handle)) === false)
			{
				return false;
			}

			if (!mb_detect_encoding($ln, 'UTF-8', true))
			{
				$ln = utf8_encode($ln);
			}

			if ($strip_tags)
			{
				$ln = trim(strip_tags($ln));
			}

			if ($trim)
			{
				$ln = trim($ln);
			}
		}

		return $ln;
	}


	function getInfoFromSidecar($filename, $key)
	{
		$basename = substr($filename, 0, strlen($filename) - strlen(strrchr($filename, '.')));
		$lang1 = '.' . JFactory::getLanguage()->getTag();
		$lang2 = strstr($lang1, '-', true);

		$handle = @fopen($basename . $lang1 . $this->options->sidecar_files_extension  , 'r');
		if ($handle === false)
		{
			$handle = @fopen($basename . $lang2 . $this->options->sidecar_files_extension, 'r');
		}
		if ($handle === false)
		{
			$handle = @fopen($basename . $this->options->sidecar_files_extension, 'r');
		}
		if ($handle === false)
		{
			$handle = @fopen($filename . $lang1 . $this->options->sidecar_files_extension, 'r');
		}
		if ($handle === false)
		{
			$handle = @fopen($filename . lang2 . $this->options->sidecar_files_extension, 'r');
		}
		if ($handle === false)
		{
			$handle = @fopen($filename . $this->options->sidecar_files_extension, 'r');
		}


		if ($handle)
		{
			while (($ln = $this->readLine($handle, true, true)) !== false)
			{
				// Parse the "tag:value" pair
				$tag = trim(mb_substr($ln, 0, mb_strpos($ln, $this->options->name_value_separator)));
				$taglen = mb_strlen($tag);
				$value = trim(mb_substr($ln, $taglen+2));
				$tag = trim($tag);

				if ($taglen > 0 && mb_strlen($value) > 0)
				{

					if ($tag == $this->options->title_field)
					{
						$this->gallery[$key]->title = $value;
					}
					else
					{
						$attribute = 'sigInfo-' . substr(md5($tag), 0, $this->galleryIdLength);
						$this->gallery[$key]->info->$attribute = new stdClass();
						$this->gallery[$key]->info->$attribute->for_thumbs = true;
						$this->gallery[$key]->info->$attribute->for_lightbox = true;
						switch (mb_substr($tag, 0, 1))
						{
							case $this->options->lightbox_only_field_flag:
								$this->gallery[$key]->info->$attribute->for_thumbs = false;
								$this->gallery[$key]->info->$attribute->for_lightbox = true;
								$tag = mb_substr($tag, 1);
								break;
							case $this->options->thumbs_only_field_flag:
								$this->gallery[$key]->info->$attribute->for_thumbs = true;
								$this->gallery[$key]->info->$attribute->for_lightbox = false;
								$tag = mb_substr($tag, 1);
								break;
							default:
								$this->gallery[$key]->info->$attribute->for_thumbs = true;
								$this->gallery[$key]->info->$attribute->for_lightbox = true;
						}
						$this->gallery[$key]->info->$attribute->tag = htmlentities($tag, ENT_QUOTES);
						$this->gallery[$key]->info->$attribute->value = htmlentities($value, ENT_QUOTES);
					}
				}
			}
			fclose($handle);
		}
	}


	function getInfoFromInfofile()
	{
		$filename = $this->options->galleryFolder . '/' . $this->options->info_file;
		$basename = substr($filename, 0, strlen($filename) - strlen(strrchr($filename, '.')));
		$suffix = strstr($this->options->info_file, '.');
		$lang1 = '.' . JFactory::getLanguage()->getTag();
		$lang2 = strstr($lang1, '-', true);
		$info = array();

		$handle = @fopen($basename . $lang1 . $suffix, 'r');
		if ($handle === false)
		{
			$handle = @fopen($basename . $lang2 . $suffix, 'r');
		}
		if ($handle === false)
		{
			$handle = @fopen($filename, 'r');
		}

		if ($handle)
		{
			// Read the first line as the $heading array (list of tags)
			if (is_array($heading = fgetcsv($handle)))
			{
				unset($heading[0]); // The first element ALWAYS is the filename

				// And trim the tag names
				$heading = array_map('trim', $heading);

				// Read the rest of the csv and build the $info array
				while (is_array($temp = fgetcsv($handle)))
				{
					$temp = array_map('trim', $temp);
					$key = $temp[0];
					unset($temp[0]);
					$info[$key] = $temp;
				}
				fclose($handle);

				// Now for each gallery ellement let's see if we have $info element
				foreach ($this->gallery as &$image)
				{
					if (array_key_exists($image->filename, $info))
					{
						// If we do, we move that info to the image info object (or title...)
						foreach ($heading as $key => $tag)
						{
							if (array_key_exists($key, $info[$image->filename]))
							{
								$value = $info[$image->filename][$key];
							}
							else
							{
								$value = '';
							}

							if ($tag == $this->options->title_field)
							{
								$image->title = htmlentities($value, ENT_QUOTES);
							}
							else
							{
								$attribute = 'sigInfo-' . substr(md5($tag), 0, $this->galleryIdLength);
								$image->info->$attribute = new stdClass();
								switch (mb_substr($tag, 0, 1))
								{
									case $this->options->lightbox_only_field_flag:
										$image->info->$attribute->for_thumbs = false;
										$image->info->$attribute->for_lightbox = true;
										$tag = mb_substr($tag, 1);
										break;
									case $this->options->thumbs_only_field_flag:
										$image->info->$attribute->for_thumbs = true;
										$image->info->$attribute->for_lightbox = false;
										$tag = mb_substr($tag, 1);
										break;
									default:
										$image->info->$attribute->for_thumbs = true;
										$image->info->$attribute->for_lightbox = true;
								}
								$image->info->$attribute->tag = htmlentities($tag, ENT_QUOTES);
								$image->info->$attribute->value = htmlentities($value, ENT_QUOTES);
							}
						}
					}
				}
			}
		}
	}


}
