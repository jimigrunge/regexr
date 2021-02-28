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

class rate extends \core\AbstractAction {

    public $description = 'Like, dislike ore remove ratings for any existing pattern.';

    public function execute() {
        $urlId = $this->getValue("patternId");
        $patternId = convertFromURL($urlId);
        $rating = $this->getValue("userRating");

        if (!$this->db->exists("patterns", ["id"=>$patternId])) {
            throw new \core\APIError(\core\ErrorCodes::API_PATTERN_NOT_FOUND);
        }

        $userProfile = $this->getUserProfile();
        $voteQuery = null;

        $sql = "SELECT * FROM userRatings WHERE patternId=? && userId=? LIMIT 1";
        $existing = $this->db->execute($sql, [
            ["s", $patternId],
            ["s", $userProfile->userId]
        ], true);

        if (is_null($existing)) {
            if ($rating == 1) {
                $voteQuery = "numPositiveVotes = numPositiveVotes + 1";
            } else if ($rating == -1) {
                $voteQuery = "numNegativeVotes = numNegativeVotes + 1";
            }
        } else {
            $currentRating = intval($existing->rating);

            if ($currentRating == $rating) {
                // Rating is the same, ignore it.
            } else if ($currentRating == -1 && $rating == 1) {
                $voteQuery = "numNegativeVotes = numNegativeVotes - 1, numPositiveVotes = numPositiveVotes + 1";
            } else if ($currentRating == 1 && $rating == -1) {
                $voteQuery = "numNegativeVotes = numNegativeVotes + 1, numPositiveVotes = numPositiveVotes - 1";
            } else if ($currentRating == 0 && $rating == 1) {
                $voteQuery = "numPositiveVotes = numPositiveVotes + 1";
            } else if ($currentRating == 0 && $rating == -1) {
                $voteQuery = "numNegativeVotes = numNegativeVotes + 1";
            } else if ($currentRating == 1 && $rating == 0) {
                $voteQuery = "numPositiveVotes = numPositiveVotes - 1";
            } else if ($currentRating == -1 && $rating == 0) {
                $voteQuery = "numNegativeVotes = numNegativeVotes - 1";
            }
        }

        // Tag that the user rated this pattern.
        $ip = getClientIpAddr();
        // Save rating
        $saveRatingSql = "INSERT INTO userRatings (userId, patternId, rating, ip)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `rating`=?, `lastUpdated`=NOW(), `ip`=?
        ";
        $this->db->execute($saveRatingSql, [
            ["s", $userProfile->userId],
            ["i", $patternId],
            ["s", $rating],
            ["s", $ip],
            ["s", $rating],
            ["s", $ip]
        ], $saveRatingSql);

        if (!is_null($voteQuery)) {
            $this->db->begin();
            // Change the vote count
            $changeSql = "UPDATE patterns SET {$voteQuery} WHERE id=?";
            $this->db->execute($changeSql, ["i", $patternId], true);

            // Calculate the rating sort value (based on http://www.evanmiller.org/how-not-to-sort-by-average-rating.html)
            $this->db->execute("UPDATE patterns AS src JOIN patterns as dest ON src.id = dest.id SET dest.ratingSort=((src.numPositiveVotes + 1.9208)
                            / (src.numPositiveVotes + src.numNegativeVotes) - 1.96 * SQRT((src.numPositiveVotes * src.numNegativeVotes)
                            / (src.numPositiveVotes + src.numNegativeVotes) + 0.9604)
                            / (src.numPositiveVotes + src.numNegativeVotes))
                            / (1 + 3.8416 / (src.numPositiveVotes + src.numNegativeVotes)) WHERE src.id=? AND src.numPositiveVotes + src.numNegativeVotes > 0",
                            ["i", '$patternId']);

            // Update the human rating.
            $humanRatingSql = "UPDATE patterns p,
                                (SELECT id, TRUNCATE((ratingSort / (SELECT MAX(ratingSort) FROM patterns))*5, 2) as rating FROM patterns WHERE id=?) r
                                SET p.rating=r.rating WHERE r.id = p.id
            ";

            $this->db->execute($humanRatingSql, ["i", $patternId]);

            // Update the sort
            $sortSql = "UPDATE patterns
                        SET ratingSort=0 WHERE id=?
                        AND (
                                (numPositiveVotes + numNegativeVotes = 0) OR (numPositiveVotes = 0 AND numNegativeVotes > 0)
                            )
            ";
            $this->db->execute($sortSql, ["i", $patternId]);

            $this->db->commit();
        }

        $newRating = $this->db->execute("SELECT rating FROM patterns WHERE id = ?", ["i", $patternId], true);

        return new \core\Result(['id' => $urlId, 'userRating' => $rating, 'rating' => $newRating->rating]);
    }

    public function getSchema() {
        return array(
            "patternId" => array("type"=>self::STRING, "required"=>true),
            "userRating" => array("type"=>self::ENUM, "values"=>[-1, 1, 0], "required"=>true)
        );
    }
}
