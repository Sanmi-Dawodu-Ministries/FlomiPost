<?php
/**
 * GraceParishForm — handles submissions from the Grace parish form.
 *
 * Fields: parish name, shepherd's name, city, county (optional), state, country.
 * Rows land in the `grace_parishes` table
 * (backend/migrations/2026_10_grace_parishes.sql).
 *
 * WIRING
 * ------
 * The form at public/grace/index.html POSTs JSON to /api/grace/parishes.
 * In the front controller (backend/index.php), route that path here. It must
 * be reachable WITHOUT the fp_token bearer, since the form is public:
 *
 *     if ($method === 'POST' && $path === '/api/grace/parishes') {
 *         require_once __DIR__ . '/forms/GraceParishForm.php';
 *         (new GraceParishForm($pdo))->handle();
 *         exit;
 *     }
 */
final class GraceParishForm
{
    /** field => [required, max length] */
    private const FIELDS = [
        'parish_name'   => [true, 200],
        'shepherd_name' => [true, 200],
        'city'          => [true, 120],
        'county'        => [false, 120],
        'state'         => [true, 120],
        'country'       => [true, 120],
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) {
            $input = $_POST; // plain form post fallback
        }

        // Honeypot: real users never see or fill this field. Pretend success.
        if (!empty($input['website'])) {
            $this->respond(201, ['ok' => true]);
            return;
        }

        [$data, $errors] = $this->validate($input);
        if ($errors) {
            $this->respond(422, ['ok' => false, 'errors' => $errors]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO grace_parishes
                 (parish_name, shepherd_name, city, county, state, country, submitter_ip)
             VALUES
                 (:parish_name, :shepherd_name, :city, :county, :state, :country, :ip)'
        );
        $stmt->execute($data + ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

        $this->respond(201, ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()]);
    }

    /** @return array{0: array<string, ?string>, 1: array<string, string>} */
    private function validate(array $input): array
    {
        $data = [];
        $errors = [];
        foreach (self::FIELDS as $field => [$required, $max]) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '') {
                if ($required) {
                    $errors[$field] = 'This field is required.';
                }
                $data[$field] = null;
                continue;
            }
            if (mb_strlen($value) > $max) {
                $errors[$field] = "Must be {$max} characters or fewer.";
            }
            $data[$field] = $value;
        }
        return [$data, $errors];
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
