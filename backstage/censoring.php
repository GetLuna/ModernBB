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

if ($luna_user['g_id'] != FORUM_ADMIN)
	message_backstage($lang['No permission'], false, '403 Forbidden');

// Add a censor word
if (isset($_POST['add_word']))
{
	confirm_referrer('backstage/censoring.php');
	
	$search_for = luna_trim($_POST['new_search_for']);
	$replace_with = luna_trim($_POST['new_replace_with']);

	if ($search_for == '')
		message_backstage($lang['Must enter word message']);

	$db->query('INSERT INTO '.$db->prefix.'censoring (search_for, replace_with) VALUES (\''.$db->escape($search_for).'\', \''.$db->escape($replace_with).'\')') or error('Unable to add censor word', __FILE__, __LINE__, $db->error());

	// Regenerate the censoring cache
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require FORUM_ROOT.'include/cache.php';

	generate_censoring_cache();

	redirect('backstage/censoring.php');
}

// Update a censor word
else if (isset($_POST['update']))
{
	confirm_referrer('backstage/censoring.php');
	
	$id = intval(key($_POST['update']));

	$search_for = luna_trim($_POST['search_for'][$id]);
	$replace_with = luna_trim($_POST['replace_with'][$id]);

	if ($search_for == '')
		message_backstage($lang['Must enter word message']);

	$db->query('UPDATE '.$db->prefix.'censoring SET search_for=\''.$db->escape($search_for).'\', replace_with=\''.$db->escape($replace_with).'\' WHERE id='.$id) or error('Unable to update censor word', __FILE__, __LINE__, $db->error());

	// Regenerate the censoring cache
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require FORUM_ROOT.'include/cache.php';

	generate_censoring_cache();

	redirect('backstage/censoring.php');
}

// Remove a censor word
else if (isset($_POST['remove']))
{
	confirm_referrer('backstage/censoring.php');
	
	$id = intval(key($_POST['remove']));

	$db->query('DELETE FROM '.$db->prefix.'censoring WHERE id='.$id) or error('Unable to delete censor word', __FILE__, __LINE__, $db->error());

	// Regenerate the censoring cache
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require FORUM_ROOT.'include/cache.php';

	generate_censoring_cache();

	redirect('backstage/censoring.php');
}

$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Admin'], $lang['Censoring']);
$focus_element = array('censoring', 'new_search_for');
define('FORUM_ACTIVE_PAGE', 'admin');
require FORUM_ROOT.'backstage/header.php';
	generate_admin_menu('censoring');

?>
<h2><?php echo $lang['Censoring'] ?></h2>
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title"><?php echo $lang['Add word subhead'] ?></h3>
	</div>
	<form id="censoring" method="post" action="censoring.php">
		<fieldset>
		<div class="panel-body">
			<p><?php echo $lang['Add word info'].' '.($luna_config['o_censoring'] == '1' ? sprintf($lang['Censoring enabled'], '<a href="features.php">'.$lang['Features'].'</a>') : sprintf($lang['Censoring disabled'], '<a href="features.php">'.$lang['Features'].'</a>')) ?></p>
		</div>
			<table class="table">
				<thead>
					<tr>
						<th class="col-xs-4"><?php echo $lang['Censored word label'] ?></th>
						<th class="col-xs-4"><?php echo $lang['Replacement label'] ?></th>
						<th class="col-xs-4"><?php echo $lang['Action'] ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><input type="text" class="form-control" name="new_search_for" maxlength="60" tabindex="1" /></td>
						<td><input type="text" class="form-control" name="new_replace_with" maxlength="60" tabindex="2" /></td>
						<td><input class="btn btn-primary" type="submit" name="add_word" value="<?php echo $lang['Add'] ?>" tabindex="3" /></td>
					</tr>
				</tbody>
			</table>
		</fieldset>
	</form>
</div>
<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title"><?php echo $lang['Edit remove words'] ?></h3>
    </div>
	<form id="censoring" method="post" action="censoring.php">
		<fieldset>
<?php

$result = $db->query('SELECT id, search_for, replace_with FROM '.$db->prefix.'censoring ORDER BY id') or error('Unable to fetch censor word list', __FILE__, __LINE__, $db->error());
if ($db->num_rows($result))
{

?>
			<table class="table table-striped table-hover">
				<thead>
					<tr>
						<th class="col-xs-4"><?php echo $lang['Censored word label'] ?></th>
						<th class="col-xs-4"><?php echo $lang['Replacement label'] ?></th>
						<th class="col-xs-4"><?php echo $lang['Action'] ?></th>
					</tr>
				</thead>
				<tbody>
<?php

while ($cur_word = $db->fetch_assoc($result))
echo "\t\t\t\t\t\t\t\t".'<tr><td><div class="btn-group"><input type="text" class="form-control" name="search_for['.$cur_word['id'].']" value="'.luna_htmlspecialchars($cur_word['search_for']).'" maxlength="60" /></div></td><td><div class="btn-group"><input type="text" class="form-control" name="replace_with['.$cur_word['id'].']" value="'.luna_htmlspecialchars($cur_word['replace_with']).'" maxlength="60" /></div></td><td><div class="btn-group"><input class="btn btn-primary" type="submit" name="update['.$cur_word['id'].']" value="'.$lang['Update'].'" /><input class="btn btn-danger" type="submit" name="remove['.$cur_word['id'].']" value="'.$lang['Remove'].'" /></div></td></tr>'."\n";

?>
				</tbody>
			</table>
<?php

}
else
echo "\t\t\t\t\t\t\t".'<div class="panel-body"><p>'.$lang['No words in list'].'</p></div>'."\n";

?>
            </fieldset>
        </form>
</div>
<?php

require FORUM_ROOT.'backstage/footer.php';
