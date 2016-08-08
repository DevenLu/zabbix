<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerAcknowledgeEdit extends CController {

	protected function init() {
		$this->disableSIDValidation();
	}

	protected function checkInput() {
		$fields = [
			'eventids' =>			'required|array_db acknowledges.eventid',
			'message' =>			'db acknowledges.message',
			'acknowledge_type' =>	'in '.ZBX_ACKNOWLEDGE_SELECTED.','.ZBX_ACKNOWLEDGE_PROBLEM.','.ZBX_ACKNOWLEDGE_ALL,
			'close_problem' =>		'db acknowledges.action|in '.
										ZBX_ACKNOWLEDGE_ACTION_NONE.','.ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM,
			'backurl' =>			'string'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			$backurl = $this->getInput('backurl', 'tr_status.php');

			switch (parse_url($backurl, PHP_URL_PATH)) {
				case 'events.php':
				case 'overview.php':
				case 'screenedit.php':
				case 'screens.php':
				case 'slides.php':
				case 'tr_events.php':
				case 'tr_status.php':
				case 'zabbix.php':
					break;

				default:
					$ret = false;
			}
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions() {
		$events = API::Event()->get([
			'eventids' => $this->getInput('eventids'),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'countOutput' => true
		]);

		return ($events == count($this->getInput('eventids')));
	}

	protected function doAction() {
		$data = [
			'sid' => $this->getUserSID(),
			'eventids' => $this->getInput('eventids'),
			'message' => $this->getInput('message', ''),
			'close_problem' => $this->getInput('close_problem', ZBX_ACKNOWLEDGE_ACTION_NONE),
			'acknowledge_type' => $this->getInput('acknowledge_type', ZBX_ACKNOWLEDGE_SELECTED),
			'backurl' => $this->getInput('backurl', 'tr_status.php'),
			'unack_problem_events_count' => 0,
			'unack_events_count' => 0
		];

		if (count($this->getInput('eventids')) == 1) {
			$events = API::Event()->get([
				'output' => [],
				'select_acknowledges' => ['clock', 'message', 'action', 'alias', 'name', 'surname'],
				'eventids' => $this->getInput('eventids'),
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if ($events) {
				$data['event'] = [
					'acknowledges' => $events[0]['acknowledges']
				];
				order_result($data['acknowledges'], 'clock', ZBX_SORT_DOWN);
			}
		}

		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'acknowledged', 'value'],
			'eventids' => $this->getInput('eventids'),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'preservekeys' => true
		]);

		$triggerids = [];

		foreach ($events as $event) {
			if ($event['acknowledged'] == EVENT_ACKNOWLEDGED) {
				$data['unack_problem_events_count']++;
				$data['unack_events_count']++;
			}
			elseif ($event['value'] == TRIGGER_VALUE_FALSE) {
				$data['unack_problem_events_count']++;
			}
			$triggerids[$event['objectid']] = true;
		}

		$triggerids = array_keys($triggerids);

		$trigger_cond = false;
		$event_cond = false;
		$events_closed = 0;
		$data['close_problem_chbox'] = false;

		// Get triggers that user should have RW permissions and is allowed manual close.
		$triggers = API::Trigger()->get([
			'output' => [],
			'triggerids' => $triggerids,
			'filter' => ['manual_close' => ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED],
			'editable' => true,
			'preservekeys' => true
		]);

		// At least one trigger should have RW permissions and should be allowed manual close.
		foreach ($triggerids as $triggerid) {
			if (array_key_exists($triggerid, $triggers)) {
				$trigger_cond = true;
				break;
			}
		}

		// Get events in problem state with acknowledges.
		$problems_events = API::Event()->get([
			'output' => ['eventid'],
			'select_acknowledges' => ['action'],
			'filter' => ['value' => TRIGGER_VALUE_TRUE],
			'eventids' => array_keys($events),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'preservekeys' => true
		]);

		// At least one event should not be closed.
		foreach ($problems_events as $problem_event) {
			if ($problem_event['acknowledges']) {
				foreach ($problem_event['acknowledges'] as $acknowledge) {
					if ($acknowledge['action'] == ZBX_ACKNOWLEDGE_ACTION_NONE) {
						// If at least one event is not opened, checkbox could potentially be enabled.
						$event_cond = true;
					}
					else {
						// Count events closed. If in the end all are closed, checkbox is definitely disabled.
						$events_closed++;
					}
				}
			}
			else {
				// No acknowledges yet, so event is still open.
				$event_cond = true;
				break;
			}
		}

		if ($events_closed == count($problems_events)) {
			$event_cond = false;
		}

		/*
		 * Show checkbox as enabled if trigger conditions (has permissions and allowed to close) and
		 * event conditions (problem state and not closed) are both set to true. Otherwise checkbox is disabled.
		 */

		if ($trigger_cond && $event_cond) {
			$data['close_problem_chbox'] = true;
		}

		$data['unack_problem_events_count'] += API::Event()->get([
			'countOutput' => true,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => $triggerids,
			'filter' => [
				'acknowledged' => EVENT_NOT_ACKNOWLEDGED,
				'value' => TRIGGER_VALUE_TRUE
			]
		]);

		$data['unack_events_count'] += API::Event()->get([
			'countOutput' => true,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => $triggerids,
			'filter' => [
				'acknowledged' => EVENT_NOT_ACKNOWLEDGED
			]
		]);

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Alarm acknowledgements'));
		$this->setResponse($response);
	}
}

