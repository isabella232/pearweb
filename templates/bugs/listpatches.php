<div class="explain">
<table>
 <tr>
  <td>
   <a href="bug.php?id=<?php echo urlencode($bug) ?>">Return to Bug #<?php echo clean($bug) ?></a>
   <?php if ($canpatch): ?> | <a href="patch-add.php?bug=<?php echo urlencode($bug) ?>">Add a Patch</a>
   <?php endif; //if ($canpatch) ?>
  </td>
 </tr>
<?php if (!count($patches)): ?>
 <tr>
  <th class="form-label_left">
   No patches
  </th>
 </tr>
<?php else: //if (!count($patches))
    foreach ($patches as $patch => $revisions):
?>
 <tr>
  <th class="details">
   Patch <a href="?bug=<?php echo urlencode($bug) ?>&patch=<?php echo urlencode($patch)
      ?>"><?php echo clean($patch); ?></a>
  </th>
  <td>
   <?php foreach ($revisions as $rev): ?>
   revision <a href="patch-display.php?bug=<?php echo urlencode($bug) ?>&patch=<?php echo urlencode($patch)
      ?>&revision=<?php echo $rev[0] ?>&display=1"><?php echo format_date($rev[0]) ?></a> by <a href="/user/<?php echo $rev[1] ?>"><?php echo $rev[1] ?></a><br />
   <?php endforeach; //foreach ($revisions as $rev) ?>
  </td>
 </tr>
<?php
    endforeach; //foreach ($patches as $name => $rev)
endif; //if (!count($patches)) ?>
</table>
</div>
