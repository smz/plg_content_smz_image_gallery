<?php
/**
 * @package		SMZ Image Gallery (plugin)
 * @author		Sergio Manzi - http://smz.it
 * @copyright	Copyright (c) 2013 - 2015 Sergio Manzi. All rights reserved.
 * @license		GNU General Public License version 3 or (at your option) any later version.
 * @version		3.6.0
 */

defined('_JEXEC') or die;

echo "<div id='sigId-{$this->gallery_id}' class='sigContainer classic'{$this->masonry_options}>";

foreach($this->gallery as $photo)
{
	$LightboxPhotoInfo = $photo->title;

	foreach ($photo->info as $info)
	{
		if ($info->for_lightbox)
		{
			if ($info->tag[0] == '!')
			{
				$LightboxPhotoInfo .= " - {$info->value}";
			}
			else
			{
				$LightboxPhotoInfo .= " - {$info->tag}: {$info->value}";
			}
		}
	}

	echo "<div class='sigCell' style='margin-bottom:{$this->options->margin_bottom}px; margin-right:{$this->options->margin_right}px'>";
	echo "<div class='sigOuterWrapper'><div class='sigInnerWrapper'>";
	echo "<div class='sigImageWrapper' style='width:{$this->options->thb_width}px;height:{$this->options->thb_height}px;'>";

	if ($this->options->use_fancybox)
	{
		echo "<a href='{$photo->sourceImageURL}' class='sigLink{$this->options->lightbox}' {$this->options->fancybox_grouping}='{$this->options->fancybox_group}' title='{$LightboxPhotoInfo}' target='_blank'>";
	}

	echo "<img class='sigImage' src='{$photo->thumbImageURL}' alt='' title='{$photo->title}' style='width:{$photo->width}px;height:{$photo->height}px;' />";

	if ($this->options->use_fancybox)
	{
		echo "</a>";
	}

	echo "</div>";

	echo "<div class='sigInfoWrapper' style='width:{$this->options->thb_width}px;'>";
	echo "<span class='sigInfo-title'>{$photo->title}</span>";
	if (!empty((array)$photo->info))
	{
		echo "<dl>";
		foreach ($photo->info as $tagID => $info)
		{
			if ($info->for_thumbs)
			{
				if ($info->tag[0] == '!')
				{
					echo "<dt class='{$tagID}' style='display:none;'>" . substr($info->tag,1) . '</dt>';
				}
				else
				{
					echo "<dt class='{$tagID}'>{$info->tag}</dt>";
				}
				echo "<dd>{$info->value}</dd>";
			}
		}
		echo "</dl>";
	}
	echo "</div>";

	echo "</div></div></div>";
}

echo "</div>";

if ($this->pageURL !== false)
{
	echo "<div class='sigPrintMessage'><span class='sigPrintMessageTXT'>" . JText::_('PLG_SMZ_SIG_PRINT_MESSAGE') . ": </span><span class='sigPrintMessageURL'>{$this->pageURL}</span></div>";
}
