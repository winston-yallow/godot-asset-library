<?php
// Asset editing routes

function _submit_asset_edit($c, $response, $body, $user_id, $asset_id=-1) {
  $query = $c->queries['asset_edit']['submit'];
  $query->bindValue(':user_id', $user_id, PDO::PARAM_INT);
  $query->bindValue(':asset_id', $asset_id, PDO::PARAM_INT);

  $error = _insert_asset_edit_fields($c, false, $response, $query, $body, $asset_id==-1);
  if($error) return $response;

  $query->execute();
  $error = $c->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  $id = $c->db->lastInsertId();

  if(isset($body['previews'])) {
    $error = _add_previews_to_edit($c, $error, $response, $id, $body['previews'], $asset_id==-1);
    if($error) return $response;
  }

  return $response->withJson([
    'id' => $id,
    'url' => 'asset/edit/' . $id,
  ], 200);
}

function _insert_asset_edit_fields($c, $error, &$response, $query, $body, $required=false) {
  if($error) return true;

  foreach ($c->constants['asset_edit_fields'] as $i => $field) {
    if(!$required) {
      if(isset($body[$field])) {
        $query->bindValue(':' . $field, $body[$field]);
      } else {
        $query->bindValue(':' . $field, null, PDO::PARAM_NULL);
      }
    } else {
      $error = $c->utils->error_reponse_if_missing_or_not_string($error, $response, $body, $field);
      if(!$error) $query->bindValue(':' . $field, $body[$field]);
    }
  }
  return $error;
}

function _add_previews_to_edit($c, $error, &$response, $edit_id, $previews, $required=false) {
  if($error) return true;

  foreach ($previews as $i => $preview) {
    if(!isset($preview['enabled']) || !$preview['enabled']) continue;
    if($required || !isset($preview['edit_preview_id'])) {

      $query = $c->queries['asset_edit']['add_preview'];

      $error = $c->utils->error_reponse_if_missing_or_not_string($error, $response, $preview, 'operation');
      if($error) return $error;

      $operation = $c->constants['edit_preview_operation']['insert'];

      if(!$required && isset($c->constants['edit_preview_operation'][$preview['operation']])) {
        $operation = $c->constants['edit_preview_operation'][$preview['operation']];
      }
      $query->bindValue(':operation',(int) $operation, PDO::PARAM_INT);

      if($operation == $c->constants['edit_preview_operation']['insert']) {
        $query->bindValue(':preview_id', -1, PDO::PARAM_INT);
      } else {
        $error = $c->utils->error_reponse_if_missing_or_not_string($error, $response, $preview, 'preview_id');
        if($error) return $error;
        $query->bindValue(':preview_id', (int) $preview['preview_id'], PDO::PARAM_INT);
      }

    } elseif(isset($preview['remove']) && $preview['remove']) {

      $query = $c->queries['asset_edit']['remove_preview'];
      $query->bindValue(':edit_preview_id', (int) $preview['edit_preview_id'], PDO::PARAM_INT);
      $query->bindValue(':edit_id', (int) $edit_id, PDO::PARAM_INT);
      $query->execute();
      $error = $c->utils->error_reponse_if_query_bad(false, $response, $query);
      if($error) return $error;

      continue;
    } else {
      $query = $c->queries['asset_edit']['update_preview'];
      $error = $c->utils->error_reponse_if_missing_or_not_string($error, $response, $preview, 'edit_preview_id');
      if($error) return $error;
      $query->bindValue(':edit_preview_id', (int) $preview['edit_preview_id'], PDO::PARAM_INT);
    }
    $query->bindValue(':edit_id', (int) $edit_id, PDO::PARAM_INT);

    foreach ($c->constants['asset_edit_preview_fields'] as $i => $field) {
      if(!$required) {
        if(isset($preview[$field])) {
          $query->bindValue(':' . $field, $preview[$field]);
        } else {
          $query->bindValue(':' . $field, null, PDO::PARAM_NULL);
        }
      } else {
        $error = $c->utils->error_reponse_if_missing_or_not_string($error, $response, $preview, $field);
        if(!$error) $query->bindValue(':' . $field, $preview[$field]);
      }
    }

    if($error) return $error;
    $query->execute();
    $error = $c->utils->error_reponse_if_query_bad(false, $response, $query);
    if($error) return $error;
  }

  return $error;
}


// Get an edit
$get_edit = function ($request, $response, $args) {
  $query = $this->queries['asset_edit']['get_one'];
  $query->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  $error = $this->utils->error_reponse_if_query_no_results($error, $response, $query);
  if($error) return $response;

  $output = $query->fetchAll();

  $previews = [];
  $unedited_previews = [];
  $unedited_previews_last_i = null;
  $asset_edit = [];

  foreach ($output as $row) {

    foreach ($row as $column => $value) {
      if($value!==null) {
        if($column==='edit_preview_id') {
          $previews[] = ['edit_preview_id' => $value];
        } elseif($column==='preview_id' || $column==='type' || $column==='link' || $column==='thumbnail') {
          $previews[count($previews) - 1][$column] = $value;
        } elseif($column==='operation') {
          $previews[count($previews) - 1][$column] = $this->constants['edit_preview_operation'][(int) $value];
        } elseif($column==='orig_type' || $column==='orig_link' || $column==='orig_thumbnail') {
          $previews[count($previews) - 1]['original'][substr($column, strlen('orig_'))] = $value;
        }
        elseif($column==='unedited_preview_id') {
          $unedited_previews[$value] = ['preview_id' => $value];
          $unedited_previews_last_i = $value;
        } elseif($column==='unedited_type' || $column==='unedited_link' || $column==='unedited_thumbnail') {
          $unedited_previews[$unedited_previews_last_i][substr($column, strlen('unedited_'))] = $value;
        }
        elseif($column==='status') {
          $asset_edit['status'] = $this->constants['edit_status'][(int) $value];
        } else {
          $asset_edit[$column] = $value;
        }
      } elseif($column!=='edit_preview_id' && $column!=='preview_id' && $column!=='type' && $column!=='link' && $column!=='thumbnail' && $column!=='operation') {
        $asset_edit[$column] = $value;
      }
    }
  }

  foreach($previews as $preview) {
    if(isset($preview['preview_id']) && isset($unedited_previews[$preview['preview_id']])) {
      unset($unedited_previews[$preview['preview_id']]);
    }
  }
  $asset_edit['previews'] = array_merge($previews, array_values($unedited_previews));

  if($asset_edit['asset_id'] != -1) {
    $query_asset = $this->queries['asset']['get_one_bare'];
    $query_asset->bindValue(':asset_id', (int) $asset_edit['asset_id'], PDO::PARAM_INT);
    $query_asset->execute();

    $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_asset);
    $error = $this->utils->error_reponse_if_query_no_results($error, $response, $query_asset);
    if($error) return $response;

    $asset = $query_asset->fetchAll()[0];

    $asset_edit['original'] = $asset;
  }

  return $response->withJson($asset_edit, 200);
};

// Binding to multiple routes
$app->get('/asset/edit/{id}', $get_edit);
if(isset($frontend) && $frontend) {
  $app->get('/asset/edit/{id}/edit', $get_edit);
}

// Submit an asset
$app->post('/asset', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  if($error) return $response;

  return _submit_asset_edit($this, $response, $body, $user_id, -1);
});


// Edit an existing asset
$app->post('/asset/{id}', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  if($error) return $response;

  // Ensure the author is editing the asset
  $query_asset = $this->queries['asset']['get_one_bare'];
  $query_asset->bindValue(':asset_id', (int) $args['id'], PDO::PARAM_INT);
  $query_asset->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_asset);
  if($error) return $response;

  $asset = $query_asset->fetchAll()[0];

  if((int) $asset['user_id'] !== (int) $user_id) {
    return $response->withJson([
      'error' => 'You are not authorized to update this asset',
    ], 403);
  }

  return _submit_asset_edit($this, $response, $body, $user_id, (int) $args['id']);
});


// Edit an existing edit
$app->post('/asset/edit/{id}', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  if($error) return $response;

  // Fetch the edit to check the user id
  $query_edit = $this->queries['asset_edit']['get_one_bare'];
  $query_edit->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query_edit->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_edit);
  $error = $this->utils->error_reponse_if_query_no_results($error, $response, $query_edit);
  if($error) return $response;

  $asset_edit = $query_edit->fetchAll()[0];

  if((int) $asset_edit['user_id'] !== (int) $user_id) {
    return $response->withJson([
      'error' => 'You are not authorized to update this asset edit',
    ], 403);
  }

  if((int) $asset_edit['status'] !== $this->constants['edit_status']['new']) {
    return $response->withJson([
      'error' => 'You are no longer allowed to update this asset edit, please make a new one',
    ], 403);
  }

  // Build query
  $query = $this->queries['asset_edit']['update'];
  $query->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);

  $error = _insert_asset_edit_fields($this, false, $response, $query, $body, false);
  if($error) return $response;

  $query->execute();
  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  if(isset($body['previews'])) {
    $error = _add_previews_to_edit($this, $error, $response, $args['id'], $body['previews'], false);
    if($error) return $response;
  }

  return $response->withJson([
    'id' => $args['id'],
    'url' => 'asset/edit/' . $args['id'],
  ], 200);
});


// Accept an edit
$app->post('/asset/edit/{id}/accept', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  $error = $this->utils->get_user_for_id($error, $response, $user_id, $user);
  $error = $this->utils->error_reponse_if_not_user_has_level($error, $response, $user, 'moderator');
  $error = $this->utils->error_reponse_if_missing_or_not_string($error, $response, $body, 'hash');
  if($error) return $response;

  // Get the edit
  $query_edit = $this->queries['asset_edit']['get_one_bare'];
  $query_edit->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query_edit->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_edit);
  $error = $this->utils->error_reponse_if_query_no_results($error, $response, $query_edit);
  if($error) return $response;

  $asset_edit_previews = $query_edit->fetchAll();
  $asset_edit = $asset_edit_previews[0];
  if((int) $asset_edit['status'] !== $this->constants['edit_status']['in_review']) {
    return $response->withJson([
      'error' => 'The edit should be in review in order to be accepted',
    ], 403);
  }

  // Update the status to prevent race conditions
  $query_status = $this->queries['asset_edit']['set_status_and_reason'];

  $query_status->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query_status->bindValue(':status', (int) $this->constants['edit_status']['accepted'], PDO::PARAM_INT);
  $query_status->bindValue(':reason', "");

  $query_status->execute();
  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_status);
  $error = $this->utils->error_reponse_if_query_no_results(false, $response, $query_status); // Ensure that something was actually changed
  if($error) return $response;

  // Start building the query
  $query = null;

  if((int) $asset_edit['asset_id'] === -1) {
    $query = $this->queries['asset']['apply_creational_edit'];
    $query->bindValue(':user_id', (int) $asset_edit['user_id'], PDO::PARAM_INT);
  } else {
    $query = $this->queries['asset']['apply_edit'];
    $query->bindValue(':asset_id', (int) $asset_edit['asset_id'], PDO::PARAM_INT);
  }

  // Params
  $update_version = false;
  foreach ($this->constants['asset_edit_fields'] as $i => $field) {
    if(isset($asset_edit[$field]) && $asset_edit[$field] !== null) {
      $query->bindValue(':' . $field, $asset_edit[$field]);
      $update_version = $update_version || ($field === 'download_url' || $field === 'browse_url' || $field === 'version_string');
    } else {
      $query->bindValue(':' . $field, null, PDO::PARAM_NULL);
    }
  }
  $query->bindValue(':update_version', (int) $update_version, PDO::PARAM_INT);
  $query->bindValue(':download_hash', $body['hash']);

  // Run
  $query->execute();
  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  // Update the id in case it was newly-created
  if((int) $asset_edit['asset_id'] === -1) {
    $asset_edit['asset_id'] = $this->db->lastInsertId();

    $query_update_id = $this->queries['asset_edit']['set_asset_id'];

    $query_update_id->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
    $query_update_id->bindValue(':asset_id', (int) $asset_edit['asset_id'], PDO::PARAM_INT);

    $query_update_id->execute();

    $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_update_id);
    if($error) return $response;
  }

  foreach($asset_edit_previews as $i => $preview) {
    if(!isset($preview['edit_preview_id']) || $preview['edit_preview_id'] == null) continue;
    $operation = $this->constants['edit_preview_operation'][$preview['operation']];
    $query_apply_preview = $this->queries['asset']['apply_preview_edit_' . $operation];

    if($operation == 'insert') {
      $query_apply_preview->bindValue(':asset_id', (int) $asset_edit['asset_id']);
    }

    if($operation == 'remove' || $operation == 'update') {
      $query_apply_preview->bindValue(':preview_id', (int) $asset_edit['preview_id']);
    }

    if($operation == 'insert' || $operation == 'update') {
      foreach ($this->constants['asset_edit_preview_fields'] as $i => $field) {
        if(isset($preview[$field])) {
          $query_apply_preview->bindValue(':' . $field, $preview[$field]);
        } else {
          $query_apply_preview->bindValue(':' . $field, null, PDO::PARAM_NULL);
        }
      }
    }

    $query_apply_preview->execute();
    $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_apply_preview);
    if($error) return $response;
  }

  return $response->withJson([
    'id' => $asset_edit['asset_id'],
    'url' => 'asset/' . $asset_edit['asset_id'],
  ], 200);
});

// Review an edit
$app->post('/asset/edit/{id}/review', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  $error = $this->utils->get_user_for_id($error, $response, $user_id, $user);
  $error = $this->utils->error_reponse_if_not_user_has_level($error, $response, $user, 'moderator');
  if($error) return $response;

  // Get the edit
  $query_edit = $this->queries['asset_edit']['get_one_bare'];
  $query_edit->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query_edit->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query_edit);
  $error = $this->utils->error_reponse_if_query_no_results($error, $response, $query_edit);
  if($error) return $response;

  $asset_edit = $query_edit->fetchAll()[0];
  if((int) $asset_edit['status'] > $this->constants['edit_status']['in_review']) {
    return $response->withJson([
      'error' => 'The edit should be new in order to be put in review',
    ], 403);
  }

  // Do the change
  $query = $this->queries['asset_edit']['set_status_and_reason'];

  $query->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query->bindValue(':status', (int) $this->constants['edit_status']['in_review'], PDO::PARAM_INT);
  $query->bindValue(':reason', "");

  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  $asset_edit['status'] = 'in_review'; // Prepare to send
  $asset_edit['url'] = 'asset/edit/' . $args['id'];

  return $response->withJson($asset_edit, 200);
});


// Reject an edit
$app->post('/asset/edit/{id}/reject', function ($request, $response, $args) {
  $body = $request->getParsedBody();

  $error = $this->utils->ensure_logged_in(false, $response, $body, $user_id);
  $error = $this->utils->get_user_for_id($error, $response, $user_id, $user);
  $error = $this->utils->error_reponse_if_not_user_has_level($error, $response, $user, 'moderator');
  $error = $this->utils->error_reponse_if_missing_or_not_string($error, $response, $body, 'reason');
  if($error) return $response;

  $query = $this->queries['asset_edit']['set_status_and_reason'];

  $query->bindValue(':edit_id', (int) $args['id'], PDO::PARAM_INT);
  $query->bindValue(':status', (int) $this->constants['edit_status']['rejected'], PDO::PARAM_INT);
  $query->bindValue(':reason', $body['reason']);

  $query->execute();

  $error = $this->utils->error_reponse_if_query_bad(false, $response, $query);
  if($error) return $response;

  return $response->withJson([
    'rejected' => true,
    'url' => 'asset/edit/' . $args['id'],
  ], 200);
});
