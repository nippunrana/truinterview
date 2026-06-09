<?php
header('Content-Type: text/plain');
echo shell_exec('git diff interview.php 2>&1');
echo "\n\n=== HEAD INTERVIEW ===\n\n";
echo shell_exec('git show HEAD:interview.php 2>&1');
