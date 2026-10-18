<?php

defined('C5_EXECUTE') or die('Access Denied.');

$mode = 'event';
$showCompleted = 1;
$showParticipants = 1;
$showMatches = 1;
$showStandings = 1;
$showBracket = 1;
$bracketHeight = 500;
$refreshSeconds = 60;
$selectedIDs = [];
include __DIR__ . '/form.php';
