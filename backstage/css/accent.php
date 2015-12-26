<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * Licensed under GPLv3 (http://modernbb.be/license.php)
 */

	if (!isset($luna_user['backstage_color']))
		$accent = '#14a3ff';
	else
		$accent = $luna_user['backstage_color'];

?>
<style>
.navbar-default, .panel-default .panel-heading, .btn-primary, .btn-primary:focus, .btn-primary:active, .active.btn-primary, .open .btn-primary.dropdown-toggle {
	background-color: <?php echo $accent ?>;
}

.jumboheader {
	background-color: <?php echo $accent ?>;
}

.panel-primary > .panel-heading, .btn-primary:hover, .btn-primary:focus, .btn-primary:active, .pagination > .active > a, .pagination > .active > span, .pagination > .active > a:hover, .pagination > .active > span:hover, .pagination > .active > a:focus, .pagination > .active > span:focus {
	background-color: <?php echo $accent ?>;
}

.panel-default .panel-heading {
	border-color: <?php echo $accent ?>;
}

.btn-primary {
	border-color: <?php echo $accent ?>;
}

.panel-primary > .panel-heading, .btn-primary:hover, .pagination > .active > a, .pagination > .active > span, .pagination > .active > a:hover, .pagination > .active > span:hover, .pagination > .active > a:focus, .pagination > .active > span:focus {
	border-color: <?php echo $accent ?>;
}

@media all and (max-width:767px) {
	.nav-tabs > li.active > a, .nav-tabs > li.active > a:hover, .nav-tabs > li.active > a:focus {
		background-color: <?php echo $accent ?>;
		border-color: <?php echo $accent ?>;
	}
}
</style>