<?php

// Make sure no one attempts to run this view directly.
if (!defined('FORUM'))
	exit;

?>

<h2 class="profile-h2"><?php echo $lang['Confirm delete user'] ?></h2>
<form id="confirm_del_user" method="post" action="profile.php?id=<?php echo $id ?>">
	<fieldset>
		<div class="panel panel-danger">
			<div class="panel-heading">
				<h3 class="panel-title"><?php echo $lang['Confirmation info'].' <strong>'.luna_htmlspecialchars($username).'</strong>' ?></h3>
			</div>
			<div class="panel-body">
				<?php echo $lang['Delete warning'] ?>
				<div class="checkbox">
					<label>
						<input type="checkbox" name="delete_posts" value="1" checked="checked" />
						<?php echo $lang['Delete all posts'] ?>
					</label>
				</div>
			</div>
			<div class="panel-footer">
				<input type="submit" class="btn btn-primary" name="delete_user_comply" value="<?php echo $lang['Delete'] ?>" /> <a class="btn btn-link" href="javascript:history.go(-1)"><?php echo $lang['Go back'] ?></a>
			</div>
		</div>
	</fieldset>
</form>
<?php

	require FORUM_ROOT.'footer.php';