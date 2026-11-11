<?php
// VRCX heißt jetzt „VRC Nexus“ – alte Lesezeichen leiten weiter.
header('Location: nexus.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
