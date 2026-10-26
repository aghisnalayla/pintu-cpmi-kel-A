<?php

function getPeriodeAktif(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->query("
            SELECT id_periode, nama_periode, status_buka 
            FROM tb_periode_gelombang 
            WHERE is_aktif = 'Y' 
            LIMIT 1
        ");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            return $res;
        }
    } catch (PDOException $e) {
    }

    try {
        $stmt = $pdo->query("
            SELECT id_periode, nama_periode, status_buka 
            FROM tb_periode_gelombang 
            ORDER BY id_periode DESC 
            LIMIT 1
        ");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function cekKelayakanPendaftaran(PDO $pdo, string $nik, string $batchAktif): array
{
    $stmt = $pdo->prepare("
        SELECT 
            p.cpmi_id,
            COALESCE(p.batch_gelombang, p.batch_pelatihan) AS batch_terdaftar,
            sv.status
        FROM tb_pendaftaran p
        LEFT JOIN tb_status_verifikasi sv ON p.cpmi_id = sv.cpmi_id
        WHERE p.nik_sesuai_ktp = ?
        ORDER BY LENGTH(p.cpmi_id) DESC, p.cpmi_id DESC
    ");
    $stmt->execute([$nik]);
    $riwayat = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($riwayat)) {
        return ['lolos' => true, 'pesan' => 'NIK memenuhi syarat untuk mendaftar.'];
    }

    foreach ($riwayat as $row) {
        $status = strtoupper(trim($row['status'] ?? ''));

        if (in_array($status, ['MEMENUHI PERSYARATAN', 'LULUS'])) {
            return [
                'lolos' => false,
                'pesan' => "NIK ({$nik}) tidak dapat mendaftar karena telah berstatus {$status} pada batch sebelumnya (ID: {$row['cpmi_id']})."
            ];
        }

        if (in_array($status, ['MENUNGGU VERIFIKASI', 'BELUM DIVERIFIKASI', '']) && $row['batch_terdaftar'] === $batchAktif) {
            return [
                'lolos' => false,
                'pesan' => "Pendaftaran Anda pada {$batchAktif} sedang dalam proses verifikasi berkas oleh BP3MI."
            ];
        }
    }

    return ['lolos' => true, 'pesan' => 'NIK memenuhi syarat untuk mendaftar.'];
}

function generateNextCpmiId(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT cpmi_id 
        FROM tb_pendaftaran 
        WHERE cpmi_id LIKE 'CPMI-%' 
        ORDER BY LENGTH(cpmi_id) DESC, cpmi_id DESC 
        LIMIT 1
    ");
    $lastId = $stmt->fetchColumn();

    if ($lastId) {
        $nomorUrut = (int) substr($lastId, 5);
        $nomorBaru = $nomorUrut + 1;
    } else {
        $nomorBaru = 1;
    }

    return sprintf("CPMI-%04d", $nomorBaru);
}
