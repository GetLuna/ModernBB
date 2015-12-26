<?php

/**
 * Copyright (C) 2013-2014 ModernBB Group
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * Licensed under GPLv3 (http://modernbb.be/license.php)
 */

// Tell header.php to use the admin template
define('FORUM_ADMIN_CONSOLE', 1);

define('FORUM_ROOT', '../');
require FORUM_ROOT.'include/common.php';

if (!$luna_user['is_admmod']) {
    header("Location: ../login.php");
}

$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Admin'], $lang['Update']);
define('FORUM_ACTIVE_PAGE', 'admin');
require FORUM_ROOT.'backstage/header.php';
	generate_admin_menu('backstage', 'update');
	
	?>
<div class="row">
	<div class="col-sm-5">
		<div class="panel panel-default panel-luna">
			<div class="panel-heading">
				<h3 class="panel-title">Luna</h3>
			</div>
			<div class="panel-body">
				<p class="lead">ModernBB has a new name and a ton of new features in Luna!</p>
				<a href="http://getluna.org/download.php" class="btn btn-primary btn-luna btn-block btn-lg">Upgrade to Luna</a>
			</div>
		</div>
	</div>
	<div class="col-sm-7">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h3 class="panel-title"><?php echo $lang['ModernBB updates'] ?></h3>
			</div>
			<div class="panel-body">
				<h3><?php echo $lang['Latest version'] ?></h3>
				<p><?php echo $lang['ModernBB intro'].' '.Version::FORUM_VERSION ?></p>
				<div class="btn-group">
					<a href="http://getluna.org/changelog.php" class="btn btn-primary"><?php echo $lang['Changelog'] ?></a>
					<a href="http://getluna.org/releases.php" class="btn btn-primary"><?php echo $lang['Check for updates'] ?></a>
				</div>
			</div>
			<div class="panel-footer">
				<p>ModernBB is developed by the <a href="http://getluna.org/">Luna Group</a>. Copyright 2013-2015. Released under the GPLv3 license.</p>
			</div>
		</div>
	</div>
</div>
<?php

require FORUM_ROOT.'backstage/footer.php';
