<?php
/**
 * @package		SMZ Image Gallery (plugin)
 * @author		Sergio Manzi - http://smz.it
 * @copyright	Copyright (c) 2013 - 2015 Sergio Manzi. All rights reserved.
 * @license		GNU General Public License version 3 or (at your option) any later version.
 * @version		3.6.0
 */

defined('_JEXEC') or die;

echo "<div id='sigId-{$this->gallery_id}' class='sigContainer simple'{$this->masonry_options}>";

foreach($this->gallery as $photo)
{

	if ($this->options->use_fancybox)
	{
	echo "<a href='{$photo->sourceImageURL}' class='sigCell sigLink{$this->options->lightbox}' {$this->options->fancybox_grouping}='{$this->options->fancybox_group}' title='{$photo->title}'>";
		echo "<img class='sigImage' src='{$photo->thumbImageURL}' alt='' title='{$photo->title}' style='margin-right:{$this->options->margin_right}px;margin-bottom:{$this->options->margin_bottom}px'/>";
		echo '</a>';
	}
	else
	{
		echo "<img class='sigCell sigImage' src='{$photo->thumbImageURL}' alt='' title='{$photo->title}' style='margin-right:{$this->options->margin_right}px;margin-bottom:{$this->options->margin_bottom}px'/>";
	}
}

echo '</div>';
