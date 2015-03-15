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

if (isset($_POST['form_sent']))
{
	confirm_referrer('backstage/permissions.php');
	
	$form = array(
		'message_bbcode'		=> isset($_POST['form']['message_bbcode']) ? '1' : '0',
		'message_img_tag'		=> isset($_POST['form']['message_img_tag']) ? '1' : '0',
		'message_all_caps'		=> isset($_POST['form']['message_all_caps']) ? '1' : '0',
		'subject_all_caps'		=> isset($_POST['form']['subject_all_caps']) ? '1' : '0',
		'force_guest_email'		=> isset($_POST['form']['force_guest_email']) ? '1' : '0',
		'sig_bbcode'			=> isset($_POST['form']['sig_bbcode']) ? '1' : '0',
		'sig_img_tag'			=> isset($_POST['form']['sig_img_tag']) ? '1' : '0',
		'sig_all_caps'			=> isset($_POST['form']['sig_all_caps']) ? '1' : '0',
		'allow_banned_email'	=> isset($_POST['form']['allow_banned_email']) ? '1' : '0',
		'allow_dupe_email'		=> isset($_POST['form']['allow_dupe_email']) ? '1' : '0',
		'sig_length'			=> luna_trim($_POST['form']['sig_length']),
		'sig_lines'				=> luna_trim($_POST['form']['sig_lines']),
	);

	foreach ($form as $key => $input)
	{
		// Make sure the input is never a negative value
		if($input < 0)
			$input = 0;

		// Only update values that have changed
		if (array_key_exists('p_'.$key, $luna_config) && $luna_config['p_'.$key] != $input)
			$db->query('UPDATE '.$db->prefix.'config SET conf_value='.$input.' WHERE conf_name=\'p_'.$db->escape($key).'\'') or error('Unable to update board config', __FILE__, __LINE__, $db->error());
	}

	// Regenerate the config cache
	if (!defined('FORUM_CACHE_FUNCTIONS_LOADED'))
		require FORUM_ROOT.'include/cache.php';

	generate_config_cache();

	redirect('backstage/permissions.php?saved=true');
}

$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), $lang['Admin'], $lang['Permissions']);
define('FORUM_ACTIVE_PAGE', 'admin');
require FORUM_ROOT.'backstage/header.php';
	generate_admin_menu('permissions');

?>
<h2><?php echo $lang['Permissions'] ?></h2>
<?php
if (isset($_GET['saved']))
	echo '<div class="alert alert-success"><h4>'.$lang['Settings saved'].'</h4></div>'
?>
<form class="form-horizontal" method="post" action="permissions.php">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Posting subhead'] ?><span class="pull-right"><input class="btn btn-primary" type="submit" name="save" value="<?php echo $lang['Save'] ?>" /></span></h3>
        </div>
        <div class="panel-body">
            <input type="hidden" name="form_sent" value="1" />
            <fieldset>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['BBCode'] ?></label>
                    <div class="col-sm-9">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[message_bbcode]" value="1" <?php if ($luna_config['p_message_bbcode'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['BBCode help'] ?>
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[message_img_tag]" value="1" <?php if ($luna_config['p_message_img_tag'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['Image tag help'] ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['All caps'] ?></label>
                    <div class="col-sm-9">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[message_all_caps]" value="1" <?php if ($luna_config['p_message_all_caps'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['All caps message help'] ?>
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[subject_all_caps]" value="1" <?php if ($luna_config['p_subject_all_caps'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['All caps subject help'] ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['Guests'] ?></label>
                    <div class="col-sm-9">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[force_guest_email]" value="1" <?php if ($luna_config['p_force_guest_email'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['Require e-mail help'] ?>
                            </label>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Signatures subhead'] ?><span class="pull-right"><input class="btn btn-primary" type="submit" name="save" value="<?php echo $lang['Save'] ?>" /></span></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['BBCode'] ?></label>
                    <div class="col-sm-9">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[sig_bbcode]" value="1" <?php if ($luna_config['p_sig_bbcode'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['BBCode sigs help'] ?>
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[sig_img_tag]" value="1" <?php if ($luna_config['p_sig_img_tag'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['Image tag sigs help'] ?>
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[sig_all_caps]" value="1" <?php if ($luna_config['p_sig_all_caps'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['All caps sigs help'] ?>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['Max sig length label'] ?><span class="help-block"><?php echo $lang['Max sig length help'] ?></span></label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control" name="form[sig_length]" maxlength="5" value="<?php echo $luna_config['p_sig_length'] ?>" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['Max sig lines label'] ?><span class="help-block"><?php echo $lang['Max sig lines help'] ?></span></label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control" name="form[sig_lines]" maxlength="3" value="<?php echo $luna_config['p_sig_lines'] ?>" />
                    </div>
                </div>
            </fieldset>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><?php echo $lang['Registration'] ?><span class="pull-right"><input class="btn btn-primary" type="submit" name="save" value="<?php echo $lang['Save'] ?>" /></span></h3>
        </div>
        <div class="panel-body">
            <fieldset>
                <div class="form-group">
                    <label class="col-sm-3 control-label"><?php echo $lang['Registration'] ?></label>
                    <div class="col-sm-9">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[allow_banned_email]" value="1" <?php if ($luna_config['p_allow_banned_email'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['Banned e-mail help'] ?>
                            </label>
                        </div>
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="form[allow_dupe_email]" value="1" <?php if ($luna_config['p_allow_dupe_email'] == '1') echo ' checked="checked"' ?> />
                                <?php echo $lang['Duplicate e-mail help'] ?>
                            </label>
                        </div>
                    </div>
                </div>
			</fieldset>
        </div>
    </div>
</form>
<?php

require FORUM_ROOT.'backstage/footer.php';
