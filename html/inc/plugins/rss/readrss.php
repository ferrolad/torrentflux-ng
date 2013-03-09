<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

/******************************************************************************/

// general function
require_once('inc/generalfunctions.php');

// readrss functions
require_once('inc/plugins/rss/functions.readrss.php');

// require
require_once("inc/plugins/rss/lastRSS.php");

require_once("inc/plugins/PluginAbstract.php");

require_once('inc/classes/singleton/Configuration.php');
$cfg = Configuration::get_instance()->get_cfg();

// Just to be safe ;o)
if (!defined("ENT_COMPAT")) define("ENT_COMPAT", 2);
if (!defined("ENT_NOQUOTES")) define("ENT_NOQUOTES", 0);
if (!defined("ENT_QUOTES")) define("ENT_QUOTES", 3);

// THIS SHOULD BE EXTENDED FROM PLUGIN CLASS!!!
class RssReader extends PluginAbstract
{
	private $rss_list;
	private $cfg;
	
	function __construct() 
	{
		require_once('inc/classes/singleton/Configuration.php');
		$this->cfg = Configuration::get_instance()->get_cfg();
	}
	
	function handleRequest($requestData) {
		if ($_REQUEST['subaction'] == 'reset') {
			RssReader::resetAccessTime( $_REQUEST['url'] );
		}
	}
	
	function buildRssItemsArray()
	{
		// Get RSS feeds from Database
		$arURL = RssReader::GetRSSLinks();
		
		// create lastRSS object
		$rss = new lastRSS();
		
		// setup transparent cache
		$cacheDir = $this->cfg['rewrite_rss_cache_path'];
		
		if (!checkDirectory($cacheDir, 0777)) {
			//@error("Error with rss-cache-dir", "index.php?page=index", "", array($cacheDir));
			print("The rss_cache_path does not exist: " . $this->cfg['rewrite_rss_cache_path']);
			exit();
		}
		$rss->cache_dir = $cacheDir;
		$rss->cache_time = $this->cfg["rewrite_rss_cache_min"] * 60; // 1200 = 20 min.  3600 = 1 hour
		$rss->strip_html = false; // don't remove HTML from the description
		$rss->CDATA = 'strip'; // TODO: these variables should be defined by default in lastRSS. Some of them are used in the code but not initialized by the class
		
		// set vars
		// Loop through each RSS feed
		$rss_list = array();
		foreach ($arURL as $rid => $url) {
			if (isset($_REQUEST["debug"]))
				$rss->cache_time=0;
			
			$rs = $rss->Get($url);
			
			if ($rs !== false) {
				$last_visit = RssReader::getAccessTime($url); // first get the time since it was last visited
				
				// IMPORTANT: make sure this stays behind the getAccessTime rule otherwise you will never have any rss items left
				RssReader::updateAccessTime($url); // now update the time so next time the ones that were already shown will no longer be
				
				// Check this feed has a title tag:
				if (!isset($rs["title"]) || empty($rs["title"]))
					$rs["title"] = htmlentities($url, ENT_QUOTES);
				
				if (!empty( $rs["items"])) {
					// Check each item in this feed has link, title and publication date:
					$rss_items_to_show = array();
					for ($i=0; $i < count($rs["items"]); $i++) {
						// Don't include feed items without a link:
						if (
								( !isset($rs["items"][$i]["magnetURI"]) || empty($rs["items"][$i]["magnetURI"]) ) &&
								( !isset($rs["items"][$i]["enclosure_url"]) || empty($rs["items"][$i]["enclosure_url"]) ) &&
								( !isset($rs["items"][$i]["link"]) || empty($rs["items"][$i]["link"]) )
							){
							continue;
						}
		
						// Check item's pub date:
						if (!isset($rs["items"][$i]["pubDate"]) || empty($rs["items"][$i]["pubDate"]))
							$rs["items"][$i]["pubDate"] = "Unknown publication date";
						else { // check time that this rss feed item has been published
							//$now = date("D, d M Y H:i:s T"); // this is the RSS pubdate format
							$timestamp = strtotime($rs["items"][$i]["pubDate"]);
							//$last_visit = strtotime("Fri, 1 Mar 2013 17:00:00 GMT"); // get from database later on
							
							if ( $timestamp < $last_visit )
								continue;
						}
						
						// Set the label for the link title (<a href="foo" title="$label">)
						$rs["items"][$i]["label"] = $rs["items"][$i]["title"];
						
						// Check item's title:
						if (!isset($rs["items"][$i]["title"]) || empty($rs["items"][$i]["title"])) {
							// No title found for this item, create one from the link:
							$link = html_entity_decode($rs["items"][$i]["link"]);
							if (strlen($link) >= 45)
								$link = substr($link, 0, 42)."...";
							$rs["items"][$i]["title"] = "Unknown feed item title: $link";
						} elseif(strlen($rs["items"][$i]["title"]) >= 67){
							// if title string is longer than 70, truncate it:
							// Note this is a quick hack, link titles will also be truncated as well
							// as the feed's display title in the table.
							$rs["items"][$i]["title"] = substr($rs["items"][$i]["title"], 0, 64)."...";
						}
						
						// decode html entities like &amp; -> & , and then uri_encode them them & -> %26
						// This is needed to get Urls with more than one GET Parameter working
						// (There are 3 common fields used for torrents: enclosure_url, link, magnetURI; enclosure_url is better than link as it is more generally used)
						$rs["items"][$i]["enclosure_url"] = rawurlencode(html_entity_decode($rs["items"][$i]["enclosure_url"]));
						$rs["items"][$i]["link"] = rawurlencode(html_entity_decode($rs["items"][$i]["link"]));
						if ( isset($rs["items"][$i]["magnetURI"]) ) {
							$rs["items"][$i]["magnetURI"] = rawurlencode(html_entity_decode($rs["items"][$i]["magnetURI"]));
						}
						
						array_push($rss_items_to_show, $rs['items'][$i]);
					}
					$stat = 1;
					$message = "";
				} else {
					// feed URL is valid and active, but no feed items were found:
					$stat = 2;
					
					$message = "Feed $url is empty";
				}
			} else {
				// Unable to grab RSS feed, must of timed out
				$stat = 3;
				$message = "Feed $url isn't available";
			}
			
			array_push($rss_list, array(
				'stat' => $stat,
				'rid' => $rid,
				'title' => (isset($rs["title"]) ? $rs["title"] : ""),
				'url' => $url,
				'feedItems' => $rss_items_to_show,
				'message' => $message,
				'last_visit' => $last_visit
				)
			);
		}
		
		$this->rss_list = $rss_list;
	}
		
	//print_r($rss_list);
	
	// TODO: remove this, just to point out the array structure
	//Array
	//(
	//    [0] => Array
	//        (
	//            [stat] => 1
	//            [rid] => 0
	//            [title] => ezRSS - Search Results
	//            [url] => http://www.ezrss.it/search/index.php?show_name=family+guy&date=&quality=&release_group=&mode=rss
	//            [feedItems] => Array
	//                (
	//                    [0] => Array
	//                        (
	//                            [title] => <![CDATA[Family Guy 9x17 [HDTV - REPACK - 2HD]]]>
	//                            [link] => http%3A%2F%2Ftorrent.zoink.it%2FFamily.Guy.S09E17.REPACK.HDTV.XviD-2HD.%5Beztv%5D.torrent
	//                            [description] => <![CDATA[Show Name: Family Guy; Episode Title: N/A; Season: 9; Episode: 17]]>
	//                            [category] => <![CDATA[TV Show / Family Guy]]>
	//                            [comments] => http://eztv.it/forum/discuss/27290/
	//                            [guid] => http://eztv.it/ep/27290/family-guy-s09e17-repack-hdtv-xvid-2hd/
	//                            [pubDate] => Sun, 08 May 2011 21:01:22 -0500
	//                            [label] => <![CDATA[Family Guy 9x17 [HDTV - REPACK - 2HD]]]>
	//                        )
	
	
	
	function show()
	{
		getClientSelection();
		getActionSelection();
		print('<br>');
		
		$this->buildRssItemsArray(); // TODO: not sure were exactly is the best place for this
		
		// TODO: should this javascript be seperated?
		print('
	<script type="text/javascript" src="js/jquery.js"></script>
	<script type="text/javascript">
	function addRssTransfer(url) 
	{
	    // get other values
	    var client = $("#client").val();
	    var subaction = $("#subaction").val();
	    if ( $("#publictorrent").is(":checked") ) {
		var publictorrent = "on";
	    } else {
		var publictorrent = "off";
	    }
	
	    // validate and process form here
	    // TODO: all plugins jquery have to be edited/implemented when an option is added, which is error prone and might be fixed in a cleaner way
	    var dataString = \'url=\' + url + \'&client=\' + client + \'&action=add\' + \'&subaction=\' + subaction + \'&publictorrent=\' + publictorrent;
	
	    $.ajax({
	      type: "POST",
	      url: "dispatcher.php",
	      data: dataString,
	      success: function() {
		showstatusmessage("RSS Feed reset");
		refreshajaxdata();
	      }
	    });
	}
	
	// reset feed last visit date function
	function resetRssTransferTime(url)
	{
		var dataString = \'url=\' + url + \'&plugin=rss-transfers&action=passplugindata\' + \'&subaction=reset\';
		
		$.ajax({
	      type: "POST",
	      url: "dispatcher.php",
	      data: dataString,
	      success: function() {
		showstatusmessage("Transfer added");
		refreshajaxdata();
	      }
	    });
	}
	
	</script>
	<link rel="stylesheet" href="themes/RedRound/css/mainLayout.css" type="text/css" />
	'); // TODO: get this in a seperate javascript file
		
		print("<table cellspacing=\"0\" id=\"rss_table\" >");
		foreach($this->rss_list as $rss_source)
		{
			print("<tr><th colspan=3><img src=\"images/rss.png\">" . $rss_source['title'] . "</th></tr>\n");
			if( $rss_source['last_visit'] != '' )
				print("<tr class=gray><td colspan=3>Items since " . date("Y-m-d H:i:s", $rss_source['last_visit']) . " <img onclick=\"javascript:resetRssTransferTime('" . urlencode($rss_source['url']) . "');\"> </td></tr>\n");
			else {
				print("<tr class=gray><td colspan=3>No start date, either due to a new RSS feed or the last visited time being reset</td></tr>\n");
			}
			if ( $rss_source['message'] != '' )
				print("<tr class=gray><td colspan=3>" . $rss_source['message'] . "</td></tr>\n");
			
			if( isset($rss_source['feedItems']) && sizeof($rss_source['feedItems']) ) {
				$color_toggle = false;
				
				foreach($rss_source['feedItems'] as $feedItem)
				{
					print("<tr class=" . ($color_toggle ? "gray" : "white") . " >");
					$color_toggle = !$color_toggle;

					$rssitemline = "";
					if ( isset($feedItem['enclosure_url']) && $feedItem['enclosure_url'] !== '' ) {
						$rssitemline .= "<img src=\"images/add.png\" onclick=\"javascript:addRssTransfer('" . $feedItem['enclosure_url'] . "');\">";
					} elseif ( isset($feedItem['link']) && $feedItem['link'] !== '' ) {
						$rssitemline .= "<img src=\"images/add.png\" onclick=\"javascript:addRssTransfer('" . $feedItem['link'] . "');\">";
					}
					if ( isset($feedItem['magnetURI']) && $feedItem['magnetURI'] !== '' ) {
						$rssitemline .= "<img src=\"images/magnet_arrow.png\" onclick=\"javascript:addRssTransfer('" . $feedItem['magnetURI'] . "');\">";
					}
					print("<td size=2>&nbsp;&nbsp;$rssitemline</td>");

					print("<td>" . $feedItem['title'] . "</td>");
					
					print("<td>" . date( "Y-m-d H:i:s", strtotime($feedItem['pubDate']) ) . "</td>");

					print("</tr>");
				}
			} else {
				print ("<tr class=gray><td colspan=3><i>&nbsp;&nbsp;&nbsp;No items to show in this RSS feed</i></td></tr>");
			}
		}
		print("</table>");
	
	}

	static function getConfiguration()
	{
		print("<form method=post action=configure.php>
  <input type=hidden name=plugin value=rss-transfers>
  <input type=hidden name=action value=set>
  <input type=hidden name=subaction value=add>
  <input type=text name=url>
  <input type=submit text=Add>
</form>");

		require_once('inc/classes/singleton/db.php');
		$db = DB::get_db()->get_handle();
		
		$link_array = array();
		$sql = "SELECT rid, url FROM tf_rss ORDER BY rid";
		$link_array = $db->GetAssoc($sql);
		
		if ($db->ErrorNo() != 0) dbError($sql);

		foreach ( $link_array as $id => $url ) {
			print("<a href=\"configure.php?action=set&subaction=delete&plugin=rss-transfers&rid=$id\"><img src=images/delete.png></a>$url<br>");
		}
	}
	
	static function setConfiguration($configArray)
	{
		require_once('inc/classes/singleton/db.php');
		$db = DB::get_db()->get_handle();
		
		if ( $_REQUEST['subaction'] == "delete" ) {
			$sql = "DELETE FROM tf_rss WHERE rid=" . $_REQUEST['rid'];
			$result = $db->Execute($sql);
		
			if ($db->ErrorNo() != 0) dbError($sql);
		}
		
		if ( $_REQUEST['subaction'] == "add" ) {
			print("Nieuwe rss");
			$sql = "INSERT INTO tf_rss (url) VALUES ('" . $_REQUEST['url'] . "')";
			$result = $db->Execute($sql);
		
			print($sql);
			if ($db->ErrorNo() != 0) dbError($sql);
		}
	}
	
	static function getAccessTime($url) {
		require_once('inc/classes/singleton/db.php');
		$db = DB::get_db()->get_handle();
		
		$link_array = array();
		$sql = "SELECT last_visit FROM tf_rss WHERE url='" . $url . "'";
		$last_visit_time = $db->GetRow($sql)[0];
		
		if ($db->ErrorNo() != 0)
			AuditAction("SQL SELECT", $cfg["constants"]["error"], "SQL QUERY FAILED: ".$sql);;
		
		return $last_visit_time;
	}
	
	/**
	 * Update the access time since the last time the RSS feed was shown
	 * 
	 * @param string $url
	 * @param time() $time
	 */
	static function updateAccessTime($url, $time = '') {
		require_once('inc/classes/singleton/db.php');
		$db = DB::get_db()->get_handle();
		
		$sql = 'UPDATE tf_rss SET last_visit=\'' . ($time != '' ? $time : time()) . '\' WHERE url=\'' . $url . '\'';
		$result = $db->Execute($sql);
		
		if ($db->ErrorNo() != 0)
			AuditAction("SQL UPDATE", $cfg["constants"]["error"], "SQL QUERY FAILED: ".$sql);
	}
	
	/**
	 * Reset the time the RSS feed was last shown to Unix time 0
	 * 
	 * @param string $url
	 */
	static function resetAccessTime($url) {
		RssReader::updateAccessTime($url, "0");
	}
	
	/**
	 * get rss links
	 *
	 * @return array
	 */
	static function GetRSSLinks() {
		require_once('inc/classes/singleton/db.php');
		$db = DB::get_db()->get_handle();
	
		$link_array = array();
		$sql = "SELECT rid, url FROM tf_rss ORDER BY rid";
		$link_array = $db->GetAssoc($sql);
		if ($db->ErrorNo() != 0) dbError($sql);
		return $link_array;
	}
}

?>
