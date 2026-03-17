<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

logout_user();

redirect_to('/index.php');
