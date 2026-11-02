<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_certificatepro\local\credential;

/**
 * Builds an Open Badges 3.0 OpenBadgeCredential from an issued certificate.
 *
 * The shape is a Verifiable Credential of the W3C data model 2.0 with the
 * Open Badges context layered on top, so the outer envelope carries @context,
 * id, type, issuer, validFrom and credentialSubject, and the badge specific
 * detail hangs off the subject as an Achievement.
 *
 * Every identifier used here is a real address on this site rather than an
 * invented URN. The credential identifies itself by its public verification
 * page, the issuer by the site root, and the achievement by the activity that
 * awards it, so a verifier that follows any of them arrives somewhere useful.
 *
 * Nothing is signed here. What this class produces is the credential document;
 * proving it is a separate concern, and an unsigned credential baked into an
 * image is still a self describing record of what was awarded to whom.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class open_badge {
    /** @var string The W3C Verifiable Credentials data model 2.0 context, which must come first. */
    public const CONTEXT_CREDENTIALS = 'https://www.w3.org/ns/credentials/v2';

    /** @var string The Open Badges 3.0 context. */
    public const CONTEXT_OPEN_BADGES = 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json';

    /** @var string[] The credential types, in the order the specification's examples use. */
    public const CREDENTIAL_TYPE = ['VerifiableCredential', 'OpenBadgeCredential'];

    /** @var string[] Properties an OpenBadgeCredential cannot omit. */
    protected const REQUIRED_CREDENTIAL = [
        '@context', 'id', 'type', 'issuer', 'validFrom', 'credentialSubject',
    ];

    /** @var string[] Properties an Achievement cannot omit. */
    protected const REQUIRED_ACHIEVEMENT = ['id', 'type', 'name', 'description', 'criteria'];

    /**
     * Build the credential for an issued certificate.
     *
     * @param \stdClass $issue the issue record
     * @param \stdClass $instance the activity instance that issued it
     * @param \stdClass $course the course the activity belongs to
     * @param \stdClass $user the recipient
     * @return array the credential, ready to encode or bake
     */
    public static function build(\stdClass $issue, \stdClass $instance, \stdClass $course,
            \stdClass $user): array {
        $credential = [
            '@context' => [
                self::CONTEXT_CREDENTIALS,
                self::CONTEXT_OPEN_BADGES,
            ],
            'id' => self::credential_id($issue),
            'type' => self::CREDENTIAL_TYPE,
            'name' => self::instance_name($instance),
            'issuer' => self::issuer(),
            'validFrom' => self::timestamp((int) ($issue->timecreated ?? time())),
            'credentialSubject' => self::subject($issue, $instance, $course, $user),
        ];

        if (!empty($issue->expires)) {
            $credential['validUntil'] = self::timestamp((int) $issue->expires);
        }

        return $credential;
    }

    /**
     * Build the credential and encode it as JSON.
     *
     * @param \stdClass $issue the issue record
     * @param \stdClass $instance the activity instance that issued it
     * @param \stdClass $course the course the activity belongs to
     * @param \stdClass $user the recipient
     * @return string the credential as JSON
     */
    public static function build_json(\stdClass $issue, \stdClass $instance, \stdClass $course,
            \stdClass $user): string {
        return json_encode(
            self::build($issue, $instance, $course, $user),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Everything missing from or wrong in a credential.
     *
     * Checks the properties Open Badges 3.0 marks as required, on the
     * credential itself, on its subject and on the achievement, along with the
     * two ordering rules: the credentials context comes first, and the type
     * includes both VerifiableCredential and OpenBadgeCredential.
     *
     * @param array $credential the credential to check
     * @return string[] one description per problem, empty when the credential is well formed
     */
    public static function validate(array $credential): array {
        $problems = [];

        foreach (self::REQUIRED_CREDENTIAL as $property) {
            if (!isset($credential[$property]) || $credential[$property] === []) {
                $problems[] = 'missing ' . $property;
            }
        }

        $context = $credential['@context'] ?? [];
        if (!is_array($context) || ($context[0] ?? null) !== self::CONTEXT_CREDENTIALS) {
            $problems[] = '@context must start with ' . self::CONTEXT_CREDENTIALS;
        } else if (!in_array(self::CONTEXT_OPEN_BADGES, $context, true)) {
            $problems[] = '@context must include ' . self::CONTEXT_OPEN_BADGES;
        }

        $type = (array) ($credential['type'] ?? []);
        foreach (self::CREDENTIAL_TYPE as $expected) {
            if (!in_array($expected, $type, true)) {
                $problems[] = 'type must include ' . $expected;
            }
        }

        $issuer = $credential['issuer'] ?? [];
        if (!is_array($issuer) || empty($issuer['id'])) {
            $problems[] = 'issuer must have an id';
        } else if (!in_array('Profile', (array) ($issuer['type'] ?? []), true)) {
            $problems[] = 'issuer type must include Profile';
        }

        $subject = $credential['credentialSubject'] ?? [];
        if (!is_array($subject)) {
            $problems[] = 'credentialSubject must be an object';
            return $problems;
        }

        if (!in_array('AchievementSubject', (array) ($subject['type'] ?? []), true)) {
            $problems[] = 'credentialSubject type must include AchievementSubject';
        }

        $achievement = $subject['achievement'] ?? null;
        if (!is_array($achievement)) {
            $problems[] = 'credentialSubject must have an achievement';
            return $problems;
        }

        foreach (self::REQUIRED_ACHIEVEMENT as $property) {
            if (!isset($achievement[$property]) || $achievement[$property] === []) {
                $problems[] = 'achievement is missing ' . $property;
            }
        }

        if (!in_array('Achievement', (array) ($achievement['type'] ?? []), true)) {
            $problems[] = 'achievement type must include Achievement';
        }

        return $problems;
    }

    /**
     * The issuer profile for this site.
     *
     * @return array the Profile
     */
    protected static function issuer(): array {
        global $CFG;

        return [
            'id' => $CFG->wwwroot,
            'type' => ['Profile'],
            'name' => format_string(
                get_site()->fullname,
                true,
                ['context' => \context_system::instance()]
            ),
            'url' => $CFG->wwwroot,
        ];
    }

    /**
     * The subject of the credential: who was awarded what.
     *
     * @param \stdClass $issue the issue record
     * @param \stdClass $instance the activity instance that issued it
     * @param \stdClass $course the course the activity belongs to
     * @param \stdClass $user the recipient
     * @return array the AchievementSubject
     */
    protected static function subject(\stdClass $issue, \stdClass $instance, \stdClass $course,
            \stdClass $user): array {
        $subject = [
            'type' => ['AchievementSubject'],
            'achievement' => self::achievement($instance, $course),
        ];

        $email = trim((string) ($user->email ?? ''));

        if ($email !== '') {
            $subject['id'] = 'mailto:' . $email;
            $subject['identifier'] = [[
                'type' => 'IdentityObject',
                'hashed' => false,
                'identityHash' => $email,
                'identityType' => 'emailAddress',
            ]];
        }

        if (!empty($issue->timecreated)) {
            $subject['awardedDate'] = self::timestamp((int) $issue->timecreated);
        }

        return $subject;
    }

    /**
     * The achievement this certificate stands for.
     *
     * @param \stdClass $instance the activity instance that issued it
     * @param \stdClass $course the course the activity belongs to
     * @return array the Achievement
     */
    protected static function achievement(\stdClass $instance, \stdClass $course): array {
        $coursename = format_string(
            $course->fullname ?? '',
            true,
            ['context' => \context_system::instance()]
        );

        $description = trim(
            content_to_text(
                (string) ($instance->intro ?? ''),
                (int) ($instance->introformat ?? FORMAT_HTML)
            )
        );

        if ($description === '') {
            $description = get_string('credentialdescription', 'mod_certificatepro', $coursename);
        }

        return [
            'id' => self::achievement_id($instance, $course),
            'type' => ['Achievement'],
            'name' => self::instance_name($instance),
            'description' => $description,
            'criteria' => [
                'id' => self::achievement_id($instance, $course),
                'narrative' => get_string('credentialcriteria', 'mod_certificatepro', $coursename),
            ],
        ];
    }

    /**
     * The identifier of the credential: its public verification page.
     *
     * @param \stdClass $issue the issue record
     * @return string an absolute URL
     */
    protected static function credential_id(\stdClass $issue): string {
        $params = [];

        if (!empty($issue->code)) {
            $params['code'] = $issue->code;
        }

        return (new \moodle_url('/mod/certificatepro/verify.php', $params))->out(false);
    }

    /**
     * The identifier of the achievement: the activity that awards it.
     *
     * Falls back to the course when the activity has no course module yet,
     * which happens while a module is being created and during unit tests.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course the activity belongs to
     * @return string an absolute URL
     */
    protected static function achievement_id(\stdClass $instance, \stdClass $course): string {
        $cm = get_coursemodule_from_instance(
            'certificatepro',
            (int) $instance->id,
            (int) ($course->id ?? 0),
            false,
            IGNORE_MISSING
        );

        if ($cm) {
            return (new \moodle_url('/mod/certificatepro/view.php', ['id' => $cm->id]))->out(false);
        }

        return (new \moodle_url('/course/view.php', ['id' => (int) ($course->id ?? 0)]))->out(false);
    }

    /**
     * The activity name, formatted for output.
     *
     * @param \stdClass $instance the activity instance
     * @return string the name
     */
    protected static function instance_name(\stdClass $instance): string {
        return format_string(
            $instance->name ?? '',
            true,
            ['context' => \context_system::instance()]
        );
    }

    /**
     * A unix timestamp as the XML schema date time the data model asks for.
     *
     * @param int $time the timestamp
     * @return string the time in UTC, for example 2026-01-01T00:00:00Z
     */
    protected static function timestamp(int $time): string {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
