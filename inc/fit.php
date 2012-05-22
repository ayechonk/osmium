<?php
/* Osmium
 * Copyright (C) 2012 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\Fit;

/* KEEP THIS NAMESPACE PURE. */

function get_slottypes() {
  return array('high', 'medium', 'low', 'rig', 'subsystem');
}

function init_fit(&$fit, $typeid) {
  $row = \Osmium\Db\fetch_row(\Osmium\Db\query_params('SELECT typename FROM osmium.invships WHERE typeid = $1', array($typeid)));

  if($row === false) return false;

  $fit['cache'] = array();

  get_attributes_and_effects(array($typeid), $fit['cache']['hull']);
  $fit['cache']['hull'] = $fit['cache']['hull'][$typeid];

  $fit['hull'] = 
    array(
	  'typeid' => $typeid,
	  'typename' => $row[0]
	  );

  reset_process_hull_attributes($fit);
  
  if(!isset($fit['modules'])) {
    foreach(get_slottypes() as $type) {
      $fit['modules'][$type] = array();
    }
  }
  
  if(!isset($fit['charges'])) {
    $fit['charges'] = array(array('name' => 'Default'));
  }

  if(!isset($fit['drones'])) {
    $fit['drones'] = array();
  }
  
  return true;
}

function update_modules(&$fit, $typeids, $modules) {
  get_modules_attributes_and_effects($typeids, $fit['cache']['modules'], $fit['cache']['modules']);
  reset_process_hull_attributes($fit);

  $typeids[] = -1;
  $names = array();
  $r = \Osmium\Db\query_params('SELECT typeid, typename FROM osmium.invmodules WHERE typeid IN ('.implode(',', $typeids).')', array());
  while($row = \Osmium\Db\fetch_row($r)) {
    $names[$row[0]] = $row[1];
  }

  foreach(get_slottypes() as $type) {
    $fit['modules'][$type] = array();
  }

  foreach($modules as $type => $a) {
    foreach($a as $i => $typeid) {
      $trueslottype = get_module_slottype($fit['cache']['modules'][$typeid]['effects']);
      if($trueslottype === false) continue;
      $fit['modules'][$trueslottype][] = ($m = array('typeid' => $typeid, 
						     'typename' => $names[$typeid]));
      process_module_attributes($fit, 
				$m, 
				$fit['cache']['modules'][$typeid]['attributes'], 
				$fit['cache']['modules'][$typeid]['effects']);      
    }
  }
}

function update_drones(&$fit, $drones) {
  $keys = array_keys($drones);
  $keys[] = -1;
  
  $rows = array();
  $out = array();
  $r = \Osmium\Db\query_params('SELECT typeid, typename, volume FROM osmium.invdrones WHERE typeid IN ('.format_in_array($keys).')', array());
  while($row = \Osmium\Db\fetch_row($r)) {
    $rows[$row[0]] = $row;
  }

  foreach($drones as $typeid => $count) {
    $out[] = 
      array(
	    'typeid' => $typeid,
	    'typename' => $rows[$typeid][1],
	    'volume' => $rows[$typeid][2],
	    'count' => $count,
	    );
  }

  $fit['drones'] = $out;
}

function pop_drone(&$fit, $typeid) {
  foreach($fit['drones'] as $i => $drone) {
    if($drone['typeid'] != $typeid) continue;

    if($drone['count'] >= 2) {
      $fit['drones'][$i]['count']--;
    } else if($drone['count'] == 1) {
      unset($fit['drones'][$i]);
      $fit['drones'] = array_values($fit['drones']);
    }

    break;
  }
}

function format_in_array($arr) {
  return implode(',', array_map(function($x) { return "'$x'"; }, $arr));
}

function get_attributes_and_effects($typeids, &$out) {
  static $interesting_effects = 
    array(
	  'loPower',
	  'medPower',
	  'hiPower',
	  'rigSlot',
	  'subSystem',
	  'eliteBonusGunshipDroneCapacity2',
	  'eliteBonusHeavyGunshipDroneCapacity2',
	  'shipBonusDroneCapacityGF',
	  );
  static $interesting_attributes = 
    array(
	  'hiSlots', 
	  'medSlots', 
	  'lowSlots', 
	  'rigSlots', 
	  'maxSubSystems',
	  'hiSlotModifier',
	  'medSlotModifier',
	  'lowSlotModifier',
	  'droneCapacity',
	  'eliteBonusGunship2',
	  'eliteBonusHeavyGunship2',
	  'shipBonusGF',
	  );

  foreach($typeids as $tid) {
    $out[$tid]['effects'] = array();
    $out[$tid]['attributes'] = array();
  }

  $typeids[] = -1;
  $typeidIN = implode(',', $typeids);
  
  $effectsq = \Osmium\Db\query_params('SELECT typeid, effectname, dgmeffects.effectid
  FROM eve.dgmeffects 
  JOIN eve.dgmtypeeffects 
  ON dgmeffects.effectid = dgmtypeeffects.effectid 
  WHERE typeid IN ('.$typeidIN.') 
  AND effectname IN ('.format_in_array($interesting_effects).')', array());
  while($row = \Osmium\Db\fetch_assoc($effectsq)) {
    $tid = $row['typeid'];
    unset($row['typeid']);
    $out[$tid]['effects'][$row['effectname']] = $row;
  }

  $effectsq = \Osmium\Db\query_params('SELECT typeid, attributename, dgmattributetypes.attributeid,
  COALESCE(valuefloat, valueint) AS value 
  FROM eve.dgmattributetypes 
  JOIN eve.dgmtypeattributes ON dgmattributetypes.attributeid = dgmtypeattributes.attributeid 
  WHERE typeid IN ('.$typeidIN.') 
  AND attributename IN ('.format_in_array($interesting_attributes).')', array());
  while($row = \Osmium\Db\fetch_assoc($effectsq)) {
    $tid = $row['typeid'];
    unset($row['typeid']);
    $out[$tid]['attributes'][$row['attributename']] = $row;
  }
}

function reset_process_hull_attributes(&$fit) {
  $attributes = $fit['cache']['hull']['attributes'];
  $effects = $fit['cache']['hull']['effects'];

  foreach(array_combine(get_slottypes(), 
			array('hiSlots', 'medSlots', 'lowSlots', 'rigSlots', 'maxSubSystems')) 
	  as $type => $attributename) {
    $fit['hull']['slotcount'][$type] = isset($attributes[$attributename]) ?
      $attributes[$attributename]['value'] : 0;
  }

  $fit['hull']['dronecapacity'] = isset($attributes['droneCapacity']) ?
    $attributes['droneCapacity']['value'] : 0;

  if(isset($effects['eliteBonusGunshipDroneCapacity2'])) {
    $fit['hull']['dronecapacity'] += 5 * $attributes['eliteBonusGunship2']['value'];
  }
  if(isset($effects['eliteBonusHeavyGunshipDroneCapacity2'])) {
    $fit['hull']['dronecapacity'] += 5 * $attributes['eliteBonusHeavyGunship2']['value'];
  }
  if(isset($effects['shipBonusDroneCapacityGF'])) {
    $fit['hull']['dronecapacity'] += 5 * $attributes['shipBonusGF']['value'];
  }
}

function process_module_attributes(&$fit, $module, $attributes, $effects) {
  foreach(array('low' => 'lowSlotModifier', 
		'medium' => 'medSlotModifier', 
		'high' => 'hiSlotModifier') as $mtype => $mattribute) {
    if(isset($attributes[$mattribute])) {
      $fit['hull']['slotcount'][$mtype] += $attributes[$mattribute]['value'];
    }
  }

  if(isset($attributes['droneCapacity'])) {
    $fit['hull']['dronecapacity'] += $attributes['droneCapacity']['value'];
  }
}

function get_modules_attributes_and_effects($typeids, &$out, $cache) {
  $out = array();
  foreach($typeids as &$typeid) {
    if(isset($cache[$typeid])) {
      $out[$typeid] = $cache[$typeid];
      $typeid = -1;
    }
  }

  get_attributes_and_effects($typeids, $out);
}

function get_module_slottype($effects) {
  if(isset($effects['loPower'])) return 'low';
  if(isset($effects['medPower'])) return 'medium';
  if(isset($effects['hiPower'])) return 'high';
  if(isset($effects['rigSlot'])) return 'rig';
  if(isset($effects['subSystem'])) return 'subsystem';
  return false;
}

function reset(&$fit) {
  $fit = array();
}