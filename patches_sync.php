<?php
require_once "patchLib.php";
$patchSync = new \patchLib\patchLib();
if (!$patchSync->init()) {
	printf("[Error]: Failed to initiate the tool\n");
	exit(1);
}

if (!$patchSync->sync())
	exit(1);
