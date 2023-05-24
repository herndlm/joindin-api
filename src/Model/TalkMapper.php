<?php

namespace Joindin\Api\Model;

use Exception;
use PDO;
use Teapot\StatusCode\Http;

class TalkMapper extends ApiMapper
{
    /**
     * Iterate through results from the database to ensure data consistency and
     * add sub-resource data
     *
     * @param array|false $results
     *
     * @return array
     */
    public function processResults(array|false $results): array
    {
        if ($results === false) {
            // $results isn't an array. This shouldn't happen as an exception
            // should have been raised by PDO. However if it does, return an
            // empty array.
            return [];
        }

        if (count($results)) {
            foreach ($results as $key => $row) {
                // generate and store an inflected talk title if there isn't one
                if (empty($row['url_friendly_talk_title'])) {
                    $results[$key]['url_friendly_talk_title'] = $this->generateInflectedTitle(
                        $row['talk_title'],
                        $row['ID']
                    );
                }

                // if the stub is empty, we need to generate one and store it
                if (empty($row['stub'])) {
                    $results[$key]['stub'] = $this->generateStub($row['ID']);
                }

                // did the logged in user star this talk?
                if (isset($this->_request->user_id)) {
                    $results[$key]['starred'] = $this->hasUserStarredTalk($row['ID'], $this->_request->user_id);
                } else {
                    $results[$key]['starred'] = false;
                }

                // Did the logged in user rate this talk?
                $results[$key]['user_rating'] = false;

                if (isset($this->_request->user_id)) {
                    $results[$key]['user_rating'] = $this->getUserRatingOnTalk($row['ID'], $this->_request->user_id);
                }

                // add speakers & tracks
                $results[$key]['speakers'] = $this->getSpeakers($row['ID']);
                $results[$key]['tracks']   = $this->getTracks($row['ID']);
            }
        }

        return $results;
    }

    /**
     * Get all the talks for this event
     *
     * @param int $event_id       The event to fetch talks for
     * @param int $resultsperpage How many results to return on each page
     * @param int $start          Which result to start with
     *
     * @return false|TalkModelCollection
     */
    public function getTalksByEventId(int $event_id, int $resultsperpage, int $start): false|TalkModelCollection
    {
        $sql = $this->getBasicSQL();
        $sql .= ' and t.event_id = :event_id';
        $sql .= ' order by t.date_given';
        $sql .= $this->buildLimit($resultsperpage, $start);

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute([
            ':event_id' => $event_id
        ]);

        if ($response) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total   = $this->getTotalCount($sql, [':event_id' => $event_id]);
            $results = $this->processResults($results);

            foreach ($results as &$talk) {
                $talk = $this->addTalkMediaTypes([$talk])[0];
            }

            return new TalkModelCollection($results, $total);
        }

        return false;
    }

    /**
     * Retrieve a single talk
     *
     * @param  integer $talk_id
     * @param  bool    $verbose
     *
     * @return TalkModel|false
     */
    public function getTalkById(int $talk_id, bool $verbose = false): TalkModel|false
    {
        $sql      = $this->getBasicSQL();
        $sql      .= ' and t.ID = :talk_id';
        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(["talk_id" => $talk_id]);

        if ($response) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results) {
                $results = $this->processResults($results);

                if ($verbose) {
                    $results = $this->addTalkMediaTypes($results);
                }

                return new TalkModel($results[0]);
            }
        }

        return false;
    }

    /**
     * Search talks by title
     *
     * @param string $keyword
     * @param ?int    $resultsperpage
     * @param ?int    $start
     *
     * @return TalkModelCollection|bool Result collection or false on failure
     */
    public function getTalksByTitleSearch(string $keyword, ?int $resultsperpage, ?int $start): bool|TalkModelCollection
    {
        $sql = $this->getBasicSQL();
        $sql .= ' and LOWER(t.talk_title) like :title';
        $sql .= ' order by t.date_given desc';
        $sql .= $this->buildLimit($resultsperpage, $start);

        $data = [
            ':title' => "%" . strtolower($keyword) . "%"
        ];

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute($data);

        if ($response) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total   = $this->getTotalCount($sql, $data);

            $results = $this->processResults($results);

            return new TalkModelCollection($results, $total);
        }

        return false;
    }

    /**
     * User attending a talk?
     *
     * @param ?int $talk_id the talk to check
     * @param ?int $user_id the user you're interested in
     *
     * @return array Containing "has_starred" set to true or false
     */
    public function getUserStarred(?int $talk_id, ?int $user_id): array
    {
        return ['has_starred' => $this->hasUserStarredTalk($talk_id, $user_id)];
    }

    /**
     * Set a user as starring a talk
     *
     * @param int $talk_id The talk ID to update
     * @param int $user_id The user's ID
     *
     * @return bool
     */
    public function setUserStarred(int $talk_id, int $user_id): bool
    {
        $sql  = 'insert into user_talk_star (uid,tid) values (:uid, :tid)';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(['uid' => $user_id, 'tid' => $talk_id]);

        return true;
    }

    /**
     * Set a user as not starring a talk
     *
     * @param int $talk_id The talk ID
     * @param int $user_id The user's ID
     *
     * @return bool
     */
    public function setUserNonStarred(int $talk_id, int $user_id): bool
    {
        $sql  = 'delete from user_talk_star where uid = :uid and tid = :tid';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(['uid' => $user_id, 'tid' => $talk_id]);

        // we don't mind if the delete failed; the record didn't exist and that's fine

        return true;
    }

    /**
     * @return string
     */
    public function getBasicSQL(): string
    {
        return 'select t.*, l.lang_name, e.event_tz_place, e.event_tz_cont, '
               . '(select COUNT(ID) from talk_comments tc where tc.talk_id = t.ID
                and tc.private = 0 and tc.active = 1)
                as comment_count, '
               . '(select get_talk_rating(t.ID)) as avg_rating, '
               . '(select count(*) from user_talk_star where user_talk_star.tid = t.ID)
                as starred_count, '
               . 'CASE WHEN (((t.date_given - 3600*24) < ' . mktime(0, 0, 0)
               . ') and (t.date_given + (3*30*3600*24)) > ' . mktime(0, 0, 0)
               . ') THEN 1 ELSE 0 END as comments_enabled, '
               . 'c.cat_title as talk_type '
               . 'from talks t '
               . 'inner join events e on e.ID = t.event_id '
               . 'inner join lang l on l.ID = t.lang '
               . 'join talk_cat tc on tc.talk_id = t.ID '
               . 'join categories c on c.ID = tc.cat_id '
               . 'where t.active = 1 and '
               . 'e.active = 1 and '
               . '(e.pending = 0 or e.pending is NULL) and '
               . '(e.private <> "y" or e.private is NULL)';
    }

    /**
     * @param int $talk_id
     *
     * @return array
     */
    protected function getSpeakers(int $talk_id): array
    {
        $base    = $this->_request->base;
        $version = $this->_request->version;

        $speaker_sql  = 'select ts.*, user.full_name from talk_speaker ts '
                        . 'left join user on user.ID = ts.speaker_id '
                        . 'where ts.talk_id = :talk_id and ts.status IS NULL';
        $speaker_stmt = $this->_db->prepare($speaker_sql);
        $speaker_stmt->execute(["talk_id" => $talk_id]);
        $speakers = $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($speakers)) {
            return [];
        }

        $retval = [];

        foreach ($speakers as $person) {
            $entry = [];

            if ($person['full_name']) {
                $entry['speaker_name'] = $person['full_name'];
                $entry['speaker_uri']  = $base . '/' . $version . '/users/' . $person['speaker_id'];
            } else {
                $entry['speaker_name'] = $person['speaker_name'];
            }
            $retval[] = $entry;
        }

        return $retval;
    }

    /**
     * @param int $talk_id
     *
     * @return array
     */
    protected function getTracks(int $talk_id): array
    {
        $base       = $this->_request->base;
        $version    = $this->_request->version;
        $track_sql  = 'select et.ID,et.track_name '
                      . 'from talk_track tt '
                      . 'inner join event_track et on et.ID = tt.track_id '
                      . 'where tt.talk_id = :talk_id';
        $track_stmt = $this->_db->prepare($track_sql);
        $track_stmt->execute(["talk_id" => $talk_id]);
        $tracks = $track_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($tracks)) {
            return [];
        }

        $retval = [];

        foreach ($tracks as $track) {
            // Make the track_uri
            $track_uri        = $base . '/' . $version . '/tracks/' . $track['ID'];
            $remove_track_uri = $base . '/' . $version . '/talks/' . $talk_id . '/tracks/' . $track['ID'];
            $retval[]         = [
                'track_name'       => $track['track_name'],
                'track_uri'        => $track_uri,
                'remove_track_uri' => $remove_track_uri,
            ];
        }

        return $retval;
    }

    /**
     * @param int $user_id
     * @param int $resultsperpage
     * @param int $start
     * @param bool $verbose
     *
     * @return false|TalkModelCollection
     */
    public function getTalksBySpeaker(int $user_id, int $resultsperpage, int $start, bool $verbose = false): false|TalkModelCollection
    {
        // based on getBasicSQL() but needs the speaker table joins
        $sql = 'select t.*, l.lang_name, e.event_tz_place, e.event_tz_cont, '
               . '(select COUNT(ID) from talk_comments tc where tc.talk_id = t.ID) as comment_count, '
               . '(select get_talk_rating(t.ID)) as avg_rating, '
               . '(select count(*) from user_talk_star where user_talk_star.tid = t.ID) as starred_count, '
               . 'CASE WHEN (((t.date_given - 3600*24) < ' . mktime(0, 0, 0)
               . ') and (t.date_given + (3*30*3600*24)) > ' . mktime(0, 0, 0)
               . ') THEN 1 ELSE 0 END as comments_enabled '
               . 'from talks t '
               . 'inner join events e on e.ID = t.event_id '
               . 'inner join lang l on l.ID = t.lang '
               . 'left join talk_speaker ts on t.id = ts.talk_id '
               . 'where t.active = 1 and '
               . 'e.active = 1 and '
               . '(e.pending = 0 or e.pending is NULL) and '
               . '(e.private <> "y" or e.private is NULL) and '
               . 'ts.speaker_id = :user_id '
               . 'order by t.date_given desc';
        $sql .= $this->buildLimit($resultsperpage, $start);

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute([
            ':user_id' => $user_id
        ]);

        if ($response) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total   = $this->getTotalCount($sql, [':user_id' => $user_id]);
            $results = $this->processResults($results);

            if ($verbose) {
                $results = $this->addTalkMediaTypes($results);
            }

            return new TalkModelCollection($results, $total);
        }

        return false;
    }

    /**
     * Save the given data for the first time.
     *
     * The data-array is expected to have the following keys:
     *
     * - event_id
     * - title
     * - description
     * - slides_link
     * - language (a value from the column lang:lang_name
     * - date (a timestamp)
     * - duration
     * - speakers (an array of names)
     * - type_id (id of the talk's type)
     *
     * @param array $data
     *
     * @throws Exception
     * @return string
     */
    public function createTalk(array $data): string
    {
        // TODO map from the field mappings in getVerboseFields()
        $sql = '
          insert into talks (event_id, talk_title, talk_desc,
            lang, date_given, duration)
            values (:event_id, :talk_title, :talk_description,
            (select ID from lang where lang_name = :language),
              :date, :duration)
         ';

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute([
            ':event_id'         => $data['event_id'],
            ':talk_title'       => $data['title'],
            ':talk_description' => $data['description'],
            ':language'         => $data['language'],
            ':date'             => $data['date'],
            ':duration'         => $data['duration'],
        ]);
        $talk_id  = $this->_db->lastInsertId();

        if (0 == $talk_id) {
            throw new Exception('There has been an error storing the talk');
        }

        $this->setType($talk_id, $data['type_id']);

        if (isset($data['speakers'])) {
            $this->updateSpeakersOnTalk($talk_id, $data['speakers']);
        }

        return $talk_id;
    }

    /**
     * Edit a talk
     *
     * The data-array is expected to have the following keys:
     *
     * - title
     * - description
     * - slides_link
     * - language (a value from the column lang:lang_name
     * - date (a timestamp)
     * - duration
     * - speakers (an array of names)
     * - type_id (id of the talk's type)
     *
     * @param array $data
     * @param int   $talk_id
     *
     * @throws Exception
     */
    public function editTalk(array $data, int $talk_id): void
    {
        $sql = "UPDATE talks SET %s, url_friendly_talk_title = null WHERE ID = :talk_id";

        // get the list of columns => data key-name
        $fields = [
            'event_id'    => 'event_id',
            'title'       => 'talk_title',
            'description' => 'talk_desc',
            'date'        => 'date_given',
            'duration'    => 'duration',
            'slides_link' => 'slides_link',
        ];
        $items  = [];
        $pairs  = [];

        foreach ($fields as $api_name => $column_name) {
            if (array_key_exists($api_name, $data)) {
                $pairs[]          = "$column_name = :$api_name";
                $items[$api_name] = $data[$api_name];
            }
        }
        // language is special as we need to select the ID from lang
        $pairs[]           = 'lang = (select ID from lang where lang_name = :language)';
        $items['language'] = $data['language'];

        // add talk_id for where clause
        $items['talk_id'] = $talk_id;

        $stmt = $this->_db->prepare(sprintf($sql, implode(', ', $pairs)));

        try {
            $stmt->execute($items);
        } catch (Exception $e) {
            throw new Exception(sprintf(
                'executing "%s" resulted in an error: %s',
                $stmt->queryString,
                $e->getMessage()
            ));
        }

        if (array_key_exists('type_id', $data)) {
            $this->setType($talk_id, $data['type_id']);
        }

        if (isset($data['speakers'])) {
            $this->updateSpeakersOnTalk($talk_id, $data['speakers']);
        }
    }

    /**
     * Set the talk type for this talk
     *
     * @param int    $talk_id
     * @param string $type_id
     *
     * @return boolean
     */
    public function setType(int $talk_id, string $type_id): bool
    {
        $cat_sql  = 'select id from talk_cat where talk_id = :talk_id and cat_id = :type_id';
        $cat_stmt = $this->_db->prepare($cat_sql);
        $cat_stmt->execute([
            ':talk_id' => $talk_id,
            ':type_id' => $type_id,
        ]);

        if (count($cat_stmt->fetchAll()) > 0) {
            return true;
        }

        // remove any other types set for this talk as we only support one type per talk
        $cat_sql  = 'delete from talk_cat where talk_id = :talk_id';
        $cat_stmt = $this->_db->prepare($cat_sql);
        $cat_stmt->execute([
            ':talk_id' => $talk_id,
        ]);

        $cat_sql  = 'insert into talk_cat (talk_id, cat_id) values (:talk_id, :type_id)';
        $cat_stmt = $this->_db->prepare($cat_sql);

        return $cat_stmt->execute([
            ':talk_id' => $talk_id,
            ':type_id' => $type_id,
        ]);
    }

    /**
     * Update speakers on this talk.
     *
     * Add any new speakers not on the list
     * Remove any speakers already attached the talk that are not in this list
     * Leave the other speakers alone as they may already have claimed their talk
     *
     * @note $speakers contains the display name for each speaker
     *
     * @param  int   $talk_id
     * @param  array $speakers
     *
     * @return void
     */
    public function updateSpeakersOnTalk(int $talk_id, array $speakers): void
    {
        // get the current speakers
        $sql  = 'select '
                . 'if(ts.speaker_id,user.full_name, ts.speaker_name) as val '
                . 'from talk_speaker AS ts '
                . 'left join user on user.ID = ts.speaker_id '
                . 'where ts.talk_id = :talk_id';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(['talk_id' => $talk_id]);
        $current_speakers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        // add the speakers that aren't already attached to the talk
        $new_speakers = array_diff($speakers, $current_speakers);
        $sql          = "insert into talk_speaker
                    (talk_id, speaker_name, status)
                values
                    (:talk_id, :speaker_name, NULL)";
        $stmt         = $this->_db->prepare($sql);

        foreach ($new_speakers as $name) {
            $params = [
                'talk_id'      => $talk_id,
                'speaker_name' => $name,
            ];
            $stmt->execute($params);
        }

        // remove speakers that are currently attached to the talk, but not
        // in the list provided
        $speakers_to_delete = array_diff($current_speakers, $speakers);
        $sql                = "delete from talk_speaker WHERE
                    talk_id = :talk_id
                    and speaker_name = :speaker_name";
        $stmt               = $this->_db->prepare($sql);

        foreach ($speakers_to_delete as $name) {
            $params = [
                'talk_id'      => $talk_id,
                'speaker_name' => $name,
            ];
            $stmt->execute($params);
        }
    }

    /**
     * Is this user attending this talk?
     *
     * @param ?int $talk_id the talk of interest
     * @param ?int $user_id which user (often the current one)
     *
     * @return bool
     */
    protected function hasUserStarredTalk(?int $talk_id, ?int $user_id): bool
    {
        if ($talk_id === null || $user_id === null) {
            return false;
        }

        $sql  = "select * from user_talk_star where tid = :talk_id and uid = :user_id";
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["talk_id" => $talk_id, "user_id" => $user_id]);
        $result = $stmt->fetch();

        return is_array($result);
    }

    /**
     * Get the rating the given user has given on this talk.
     *
     * @param int $talk_id the talk of interest
     * @param int $user_id which user (often the current one)
     *
     * @return int|false
     */
    protected function getUserRatingOnTalk(int $talk_id, int $user_id): false|int
    {
        $stmt = $this->_db->prepare('
            SELECT rating
            FROM talk_comments
            WHERE talk_id = :talk_id
            AND user_id = :user_id;
        ');
        $stmt->execute([
            'talk_id' => $talk_id,
            'user_id' => $user_id,
        ]);

        $result = $stmt->fetch();

        if (!$result) {
            return false;
        }

        return $result['rating'];
    }

    /**
     * Is this user a speaker?
     *
     * @param int $talk_id the talk of interest
     * @param int $user_id which user
     *
     * @return bool
     */
    public function isUserASpeakerOnTalk(int $talk_id, int $user_id): bool
    {
        $sql  = "select ID from talk_speaker where talk_id = :talk_id and speaker_id = :user_id";
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["talk_id" => (int) $talk_id, "user_id" => (int) $user_id]);

        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }

    /**
     * This talk has no stub, so create, store and return one
     *
     * @param int $talk_id The talk that needs a new stub
     *
     * @return string|bool
     */
    protected function generateStub(int $talk_id): bool|string
    {
        $i = 0;

        while ($i < 5) {
            $stub = substr(md5(mt_rand()), 3, 5);

            try {
                return $this->storeStub($stub, $talk_id);
            } catch (Exception $e) {
                // failed to store - try again
            }
            $i++;
        }

        return '';
    }

    /**
     * Store the stub and return whether that was successful.
     * If not, we probably failed the unique check and the calling code can
     * decide what to do next
     *
     * @param string $stub    The stub for this talk
     * @param int    $talk_id The talk to store against
     *
     * @return boolean whether we stored it or not
     */
    protected function storeStub(string $stub, int $talk_id): bool
    {
        $sql = "update talks set stub = :stub
            where ID = :talk_id";

        $stmt   = $this->_db->prepare($sql);

        return $stmt->execute(["stub" => $stub, "talk_id" => $talk_id]);
    }

    /**
     * This talk doesn't have an inflected title, make and store one. Try to
     * ensure uniqueness, by adding hour and then hour-minute to the inflected
     * title.
     *
     * @param string $title   The talk title
     * @param int    $talk_id The talk to store the title against
     *
     * @return string|bool The value we stored
     */
    protected function generateInflectedTitle(string $title, int $talk_id): bool|string
    {
        // Try to store without a suffix
        $inflected_title = $this->inflect($title);
        $result          = $this->storeInflectedTitle($inflected_title, $talk_id);

        if ($result) {
            return $inflected_title;
        }

        // If that doesn't work, try to store with the talk ID tacked on
        $inflected_title .= '-' . $talk_id;
        $result          = $this->storeInflectedTitle($inflected_title, $talk_id);

        if ($result) {
            return $inflected_title;
        }

        // If neither of those worked, fail. We shouldn't get to here.
        return false;
    }

    /**
     * Try to store the inflected title against this talk
     *
     * A database constraint ensures uniqueness for url_friendly_talk_title
     * values per event, so you can have the same inflected talk title at two
     * different events, but try to have the same one at the same event and this
     * update will fail.  The calling code catches this and picks a new title
     *
     * @param string $inflected_title
     * @param int    $talk_id
     *
     * @return bool
     */
    protected function storeInflectedTitle(string $inflected_title, int $talk_id): bool
    {
        $sql = "update talks set url_friendly_talk_title = :inflected_title
            where ID = :talk_id";

        $stmt = $this->_db->prepare($sql);

        try {
            $result = $stmt->execute([
                "inflected_title" => $inflected_title,
                "talk_id"         => $talk_id
            ]);
        } catch (Exception $e) {
            return false;
        }

        return $result;
    }

    /**
     * @param int $talk_id
     *
     * @return array
     */
    public function getSpeakerEmailsByTalkId(int $talk_id): array
    {
        $speaker_sql  = 'select user.email, user.ID from talk_speaker ts '
                        . 'left join user on user.ID = ts.speaker_id '
                        . 'where ts.talk_id = :talk_id and ts.status IS NULL '
                        . 'and email IS NOT null';
        $speaker_stmt = $this->_db->prepare($speaker_sql);
        $speaker_stmt->execute(["talk_id" => $talk_id]);

        return $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Does the currently-authenticated user have rights on a particular talk?
     *
     * @param ?int $talk_id The identifier for the talk to check
     *
     * @return bool True if the user has privileges, false otherwise
     */
    public function thisUserHasAdminOn(?int $talk_id): bool
    {
        // do we even have an authenticated user?
        if ($talk_id !== null && isset($this->_request->user_id)) {
            $user_mapper = new UserMapper($this->_db, $this->_request);

            // is user site admin?
            $is_site_admin = $user_mapper->isSiteAdmin($this->_request->user_id);

            if ($is_site_admin) {
                return true;
            }

            // is user an event admin?
            $sql  = 'select a.uid as user_id, u.full_name'
                    . ' from user_admin a '
                    . ' inner join user u on u.ID = a.uid '
                    . ' inner join talks t on t.event_id = rid '
                    . ' where rtype="event" and (rcode!="pending" OR rcode is null)'
                    . ' AND u.ID = :user_id'
                    . ' AND t.ID = :talk_id';
            $stmt = $this->_db->prepare($sql);
            $stmt->execute([
                "talk_id" => $talk_id,
                "user_id" => $this->_request->user_id
            ]);
            $results = $stmt->fetchAll();

            if ($results) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add talk to track
     *
     * @param int $talk_id
     * @param int $track_id
     *
     * @return string
     */
    public function addTalkToTrack(int $talk_id, int $track_id): string
    {
        $params = [
            'track_id' => $track_id,
            'talk_id'  => $talk_id,
        ];

        // is this link in the database already?
        $sql  = 'select ID from talk_track where track_id = :track_id and talk_id = :talk_id';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute($params);
        $talk_track_id = $stmt->fetchColumn();

        if ($talk_track_id === false) {
            // insert new row as not in database
            $sql  = 'insert into talk_track (track_id, talk_id) values (:track_id, :talk_id)';
            $stmt = $this->_db->prepare($sql);
            $stmt->execute($params);

            $talk_track_id = $this->_db->lastInsertId();
        }

        return $talk_track_id;
    }

    /**
     * Remove talk from a track
     *
     * @param  int $talk_id
     * @param  int $track_id
     */
    public function removeTrackFromTalk(int $talk_id, int $track_id): void
    {
        $params = [
            'track_id' => $track_id,
            'talk_id'  => $talk_id,
        ];

        $sql  = 'delete from talk_track where track_id = :track_id and talk_id = :talk_id';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Remove a talk from all tracks it's associated with
     *
     * @param int $talk_id The ID of the talk to remove from the tracks
     *
     * @return bool
     */
    public function removeTalkFromAllTracks(int $talk_id): bool
    {
        $sql = 'DELETE FROM talk_track WHERE talk_id = :talk_id';

        $stmt = $this->_db->prepare($sql);

        return $stmt->execute(['talk_id' => $talk_id]);
    }

    /**
     * Remove a talk.
     *
     * When removing a talk we also remove all talk-links as well as the talk
     * from all tracks. When that is done, the talk is removed.
     *
     * When something breaks (or can't be deleted) the transaction is rolled back.
     *
     * @param int $talk_id
     *
     * @return bool
     */
    public function delete(int $talk_id): bool
    {
        $this->_db->beginTransaction();

        if (!$this->removeAllTalkLinks($talk_id)) {
            $this->_db->rollBack();

            return false;
        }

        if (!$this->removeTalkFromAllTracks($talk_id)) {
            $this->_db->rollBack();

            return false;
        }

        if (!$this->removeAllSpeakersFromTalk($talk_id)) {
            $this->_db->rollBack();

            return false;
        }

        $sql  = "DELETE FROM talks WHERE ID = :talk_id";
        $stmt = $this->_db->prepare($sql);

        if (!$stmt->execute(["talk_id" => $talk_id])) {
            $this->_db->rollBack();

            return false;
        }

        $this->_db->commit();

        return true;
    }

    /**
     * @param ?int   $talk_id
     * @param string $display_name
     *
     * @return array|false
     */
    public function getSpeakerFromTalk(?int $talk_id, string $display_name): array|false
    {
        if ($talk_id === null) {
            return false;
        }

        $speaker_sql  = 'select ts.* from talk_speaker ts '
                        . 'where ts.talk_id = :talk_id and ts.speaker_name = :display_name';
        $speaker_stmt = $this->_db->prepare($speaker_sql);
        $speaker_stmt->execute(["talk_id" => $talk_id, "display_name" => $display_name]);
        $speakers = $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);

        return count($speakers) > 0 ? $speakers[0] : false;
    }

    /**
     * @param int $talk_id
     * @param int $speaker_id
     */
    public function removeApprovedSpeakerFromTalk(int $talk_id, int $speaker_id): void
    {
        $sql  = 'update talk_speaker set speaker_id = null where talk_id = :talk_id and speaker_id = :speaker_id';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(
            [
                'talk_id'    => $talk_id,
                'speaker_id' => $speaker_id,
            ]
        );
    }

    /**
     * @param int $talk_id
     *
     * @return bool
     */
    public function removeAllSpeakersFromTalk(int $talk_id): bool
    {
        $sql = 'DELETE FROM talk_speaker WHERE talk_id = :talk_id';

        $stmt = $this->_db->prepare($sql);

        return $stmt->execute(['talk_id' => $talk_id]);
    }

    /**
     * @param int    $talk_id
     * @param int    $claim_id
     * @param int    $speaker_id
     * @param string $speaker_name
     *
     * @return bool
     */
    public function assignTalkToSpeaker(int $talk_id, int $claim_id, int $speaker_id, string $speaker_name): bool
    {
        $sql  = 'update talk_speaker
                SET speaker_id = :speaker_id,
                speaker_name = :speaker_name
                WHERE ID = :claim_id AND talk_id = :talk_id';
        $stmt = $this->_db->prepare($sql);

        return $stmt->execute(
            [
                'speaker_id'   => $speaker_id,
                'talk_id'      => $talk_id,
                'claim_id'     => $claim_id,
                'speaker_name' => $speaker_name
            ]
        );
    }

    /**
     * @param ?int     $talk_id
     * @param int|null $link_id
     *
     * @return array[]
     */
    public function getTalkMediaLinks(?int $talk_id, ?int $link_id = null): array
    {
        if ($talk_id === null) {
            return [];
        }

        $sql = '
            select
              tl.id,
              tlt.`display_name`,
              tl.`url`
            from
              talks
              inner join talk_links tl
                on tl.`talk_id` = talks.`id`
              inner join talk_link_types tlt
                on tlt.`id` = tl.`talk_type`
            where talk_id = :talk_id
        ';

        $params = [
            'talk_id' => $talk_id,
        ];

        if (is_numeric($link_id)) {
            $sql               .= "and tl.ID = :link_id";
            $params['link_id'] = $link_id;
        }

        $stmt = $this->_db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array $talk
     *
     * @return array
     */
    public function addTalkMediaTypes(array $talk): array
    {
        $links = $this->getTalkMediaLinks($talk[0]['ID']);

        foreach ($links as $link) {
            $talk                    = $this->handleBackwardsCompatibleMedia($talk, $link);
            $talk[0]['talk_media'][] = [$link['display_name'] => $link['url']];
        }

        return $talk;
    }

    /**
     * @param int $talk_id
     * @param int $link_id
     *
     * @return bool
     */
    public function removeTalkLink(int $talk_id, int $link_id): bool
    {
        $sql = "
            DELETE
            FROM
              talk_links
            WHERE talk_id = :talk_id
              AND id = :link_id
        ";

        $stmt = $this->_db->prepare($sql);

        return 1 == $stmt->execute(
            [
                'talk_id' => $talk_id,
                'link_id' => $link_id,
            ]
        );
    }

    /**
     * Remove all talk links foa a given talk
     *
     * @param int $talk_id The talk-ID
     *
     * @return bool
     */
    public function removeAllTalkLinks(int $talk_id): bool
    {
        $sql = "DELETE FROM talk_links WHERE talk_id = :talk_id";

        $stmt = $this->_db->prepare($sql);

        return $stmt->execute(['talk_id' => $talk_id]);
    }

    /**
     * @param int    $talk_id
     * @param int    $link_id
     * @param string $display_name
     * @param string $url
     *
     * @return bool
     */
    public function updateTalkLink(int $talk_id, int $link_id, string $display_name, string $url): bool
    {
        $sql = "
            UPDATE
              talk_links a
              INNER JOIN talk_link_types b
                ON b.`display_name` = :display_name
                SET a.`talk_type` = b.`ID`,
              a.`url` = :url
            WHERE a.`id` = :link_id
              AND a.`talk_id` = :talk_id
        ";

        $stmt = $this->_db->prepare($sql);

        $stmt->execute(
            [
                'display_name' => $display_name,
                'link_id'      => $link_id,
                'talk_id'      => $talk_id,
                'url'          => $url,
            ]
        );

        return 1 == $stmt->rowCount();
    }

    /**
     * @param int    $talk_id
     * @param string $display_name
     * @param string $url
     *
     * @return string
     */
    public function addTalkLink(int $talk_id, string $display_name, string $url): string
    {
        $sql = "
            INSERT INTO `talk_links` (`talk_id`, `talk_type`, `url`)
            SELECT
              :talk_id,
              t.ID,
              :url
            FROM
              talk_link_types t
            WHERE t.`display_name` = :display_name
        ";

        $stmt = $this->_db->prepare($sql);

        if (!($stmt->execute(
            [
                'talk_id'      => $talk_id,
                'display_name' => $display_name,
                'url'          => $url,
            ]
        ))) {
            throw new Exception(
                "The Link has not been inserted",
                Http::BAD_REQUEST
            );
        }

        return $this->_db->lastInsertId();
    }

    /**
     * Used for adding back the slide link to the request
     *
     * @param array $talk
     * @param array $link
     *
     * @return array
     */
    private function handleBackwardsCompatibleMedia(array $talk, array $link): array
    {
        if ($link['display_name'] == "slides_link") {
            $talk[0][$link['display_name']] = $link['url'];
        }

        return $talk;
    }
}
