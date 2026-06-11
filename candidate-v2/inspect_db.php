<?php
unlink(__FILE__);
header('Content-Type: application/json');
echo json_encode(['deleted' => true]);
