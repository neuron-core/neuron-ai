<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Enums;

enum MediaType: string
{
    // Images
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';
    case GIF = 'image/gif';
    case WEBP = 'image/webp';

    // Audio
    case MP3 = 'audio/mpeg';
    case WAV = 'audio/wav';
    case OGG = 'audio/ogg';
    case FLAC = 'audio/flac';
    case AAC = 'audio/aac';

    // Video
    case MP4 = 'video/mp4';
    case MPEG = 'video/mpeg';
    case MOV = 'video/quicktime';
    case WEBM = 'video/webm';

    // Documents
    case PDF = 'application/pdf';
    case TEXT = 'text/plain';
    case CSV = 'text/csv';
    case MARKDOWN = 'text/markdown';
    case HTML = 'text/html';
    case JSON = 'application/json';
    case DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}
