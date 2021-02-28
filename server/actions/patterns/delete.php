<?php
/*
RegExr: Learn, Build, & Test RegEx
Copyright (C) 2017  gskinner.com, inc.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace patterns;

class delete extends \core\AbstractAction {

    public $description = 'If a user has access, deletes a pattern.';

    public function execute() {
        $urlId = $this->getValue("patternId");
        $patternId = convertFromURL($urlId);

        $exists = $this->db->exists("patterns", ["id"=>$patternId]);
        if (!$exists) {
            throw new \core\APIError(\core\ErrorCodes::API_PATTERN_NOT_FOUND);
        }

        $userProfile = $this->getUserProfile();

        if (idx($userProfile, 'admin') != true) {
            $privateConst = \core\PatternVisibility::PRIVATE;
            $protectedConst = \core\PatternVisibility::PROTECTED;

            $sql = "SELECT id FROM patterns
                    WHERE id=?
                    && owner=?
                    && visibility IN ('$privateConst', '$protectedConst')";

            $exists = $this->db->execute($sql, [
                ["i", $patternId],
                ["s", $userProfile->userId]
            ], true);

            if (!is_null($exists)) {
                $this->db->execute("DELETE IGNORE FROM patterns WHERE id=?", ["i", $patternId]);
                $this->clean($patternId);
            } else {
                throw new \core\APIError(\core\ErrorCodes::API_NOT_ALLOWED);
            }
        } else {
            // Admins can delete anything.
            $this->db->execute("DELETE IGNORE FROM patterns WHERE id=?", ["i", $patternId]);
            $this->clean($patternId);
        }

        return new \core\Result(['id' => $urlId]);
    }

    /*
        After a delete, also remove any linked items from the DB.
    */
    private function clean($patternId) {
        $this->db->begin();
        $this->db->execute("DELETE IGNORE FROM favorites WHERE patternId=?", ["s", $patternId]);
        $this->db->execute("DELETE IGNORE FROM userRatings WHERE patternId=?", ["s", $patternId]);
        $this->db->commit();
        // We also have `patternLink` .. but not sure its required to delete this, since if we do use it we can check for a `null` pattern and assume it was deleted.
    }

    public function getSchema() {
        return array(
            "patternId" => array("type"=>self::STRING, "required"=>true)
        );
    }
}
