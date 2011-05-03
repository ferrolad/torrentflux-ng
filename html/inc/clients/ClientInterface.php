<?php

interface ClientInterface
{
	function getCapabilities();
	
	function executeAction($transfer, $action);
	
	function getTransferList($uid);
	
	function fileUploaded($fullfilename);
	
	function start($transfer);
	
	function stop($transfer);
	
	function delete($transfer);
	
	function deletewithdata($transfer);
	
	function add($url, $paused);
	
	function gettabs($tabname);
}

?>
