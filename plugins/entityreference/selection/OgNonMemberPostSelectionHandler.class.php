<?php

/**
 * @file
 * OG Public Group Posts selection handler.
 */

class OgNonMemberPostSelectionHandler extends OgSelectionHandler {

  public static function getInstance($field, $instance = NULL, $entity_type = NULL, $entity = NULL) {
    return new self($field, $instance, $entity_type, $entity);
  }

  /**
   * Overrides OgSelectionHandler::buildEntityFieldQuery().
   */
  public function buildEntityFieldQuery($match = NULL, $match_operator = 'CONTAINS') {
		
		$group_type = $this->field['settings']['target_type'];
    if (empty($this->instance['field_mode']) || $group_type != 'node') {
      return parent::buildEntityFieldQuery($match, $match_operator);
    }
	
		$field_mode = $this->instance['field_mode'];
		$bundle = reset($this->field['settings']['handler_settings']['target_bundles']);
		//$gid = $this->entity->uid;
		$roles = og_roles($group_type, $bundle); //, $gid);
		$role_permissions = og_role_permissions($roles);
		
    $handler = EntityReference_SelectionHandler_Generic::getInstance($this->field, $this->instance, $this->entity_type, $this->entity);
    $query = $handler->buildEntityFieldQuery($match, $match_operator);

    // Show only the entities that are active groups.
    $query->fieldCondition(OG_GROUP_FIELD, 'value', 1);
    
    // Add this property to make sure we will have the {node} table later on in
    // OgCommonsSelectionHandler::entityFieldQueryAlter().
    $query->propertyCondition('nid', 0, '>');
    $query->addMetaData('entityreference_selection_handler', $this);

    // FIXME: http://drupal.org/node/1325628
    unset($query->tags['node_access']);

    $query->addTag('entity_field_access');
    $query->addTag('og');
		 
		global $user;	
		if (!$user->uid) { // anon user, don't allow create access!
			$query->entityCondition('entity_id', -1, '=');
			return $query;
		}
		 

		$node = $this->entity;
		$node_type = $this->instance['bundle'];
		$ids = array();
		$oids = array();
		
		foreach ($role_permissions as $rid => $permission) {
			if ($this->has_access($permission, $node_type)) {
				$ids[$rid] = $roles[$rid];
			}
		}
	 
		if (in_array('non-member', $ids)) // then use default permissions
			return $query;

		// Check overrides
		$get_group_query = db_query("SELECT nid FROM {$group_type} WHERE type = :b", 	
			array(':b' => $bundle,)); 
		
		// Iterate through the group
		foreach ($get_group_query as $q) {	
			$qroles = og_roles($group_type, $bundle, $q->nid);
			$qrole_permissions = og_role_permissions($qroles);
			foreach ($qrole_permissions as $rid => $permission) {
				if ($this->has_access($permission, $node_type) && !in_array($q->nid, $oids)) 
					$oids[] = $q->nid;
			}
		}
		if ($oids) {
			$query->entityCondition('entity_id', $oids, 'IN');
		} else {
		// User doesn't have permission to select any group so falsify this
		// query.
			$query->entityCondition('entity_id', -1, '=');
		}
	 return $query;
  }

	/**
	 * array string -> boolean
	 * Helper for buildEntityFieldQuery. Checks if the array of permissions
	 * has create, update, or delete permissions for a given node type.
	 * @param $permission Assoc array of string permission => boolean
	 * @param $node_type The node type, e.g. committee_post
	 *
	 * @return TRUE if either create, update, or delete permissions
	 * are present in the array.
	 */
	private function has_access($permission, $node_type) {
		return in_array("create $node_type content", array_keys($permission)) ||
				in_array("update any $node_type content", array_keys($permission)) ||
				in_array("delete any $node_type content", array_keys($permission));
	}
	
  /**
   * Overrides OgSelectionHandler::entityFieldQueryAlter().
   *
   * Add the user's groups along with the rest of the "public" groups.
   */
  public function entityFieldQueryAlter(SelectQueryInterface $query) {
    $gids = og_get_entity_groups();
    if (empty($gids['node'])) {
      return;
    }
		
    $conditions = &$query->conditions();
    // Find the condition for the "field_data_field_privacy_settings" query, and
    // the one for the "node.nid", so we can later db_or() them.
    $public_condition = array();
    
    foreach ($conditions as $key => $condition) {
      if ($key !== '#conjunction' && is_string($condition['field'])) {
        if (strpos($condition['field'], 'field_data_field_og_subscribe_settings') === 0) {
          $public_condition = $condition;
          unset($conditions[$key]);
        }

        if ($condition['field'] === 'node.nid') {
          unset($conditions[$key]);
        }
      }
    }

    if (!$public_condition) {
      return;
    }

    $or = db_or();
    $or->condition($public_condition['field'], $public_condition['value'], $public_condition['operator']);
    $or->condition('node.nid', $gids['node'], 'IN');
    $query->condition($or);
  }
}
