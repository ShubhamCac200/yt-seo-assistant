<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoController extends Controller
{
    private function callGemini($prompt)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post(config('services.gemini.url') . '?key=' . config('services.gemini.key'), [
                'contents' => [
                    [
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 2000,
                ],
            ]);

            $json = $response->json();

            if (!isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return ['error' => 'Gemini returned invalid response', 'raw' => $json];
            }

            $raw = $json['candidates'][0]['content']['parts'][0]['text'];
            $clean = preg_replace('/```(json)?|```/', '', $raw);
            $clean = trim($clean);

            $result = json_decode($clean, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $clean, $matches)) {
                    $result = json_decode($matches[0], true);
                }
            }

            return $result ?: ['error' => 'Empty or invalid JSON', 'raw' => $clean];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function analyze(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'geo' => 'nullable|string',
            'audience' => 'nullable|string',
        ]);

        $prompt = <<<PROMPT
You are an expert YouTube SEO strategist.
Analyze and optimize this video’s metadata deeply and return a single JSON object containing ALL SEO intelligence.

Input:
Title: {$data['title']}
Description: {$data['description']}
Audience: {$data['audience']}
Geo: {$data['geo']}

Return JSON (no markdown, only JSON) structured exactly like this:
{
  "optimized_metadata": {
    "optimized_title": string,
    "optimized_description": string,
    "tags": [string],
    "hashtags": [string],
    "suggested_upload_time": string
  },
  "keyword_research": {
    "primary_keywords": [string],
    "secondary_keywords": [string],
    "search_intent": "Informational | Commercial | Trending",
    "competition_level": "Low | Medium | High",
    "volume_score": number
  },
  "competitor_analysis": {
    "top_competitors": [
      {"title": string, "channel": string, "views": string}
    ],
    "average_views": number,
    "common_keywords": [string]
  },
  "thumbnail_optimizer": {
    "recommended_text": string,
    "color_theme": string,
    "font_style": string,
    "emotion": string,
    "ctr_boost_tips": [string]
  },
  "seo_score_breakdown": {
    "title_score": number,
    "description_score": number,
    "keyword_density_score": number,
    "clickability_score": number,
    "overall_score": number,
    "feedback": [string]
  },
  "trends_and_topics": {
    "trending_topics": [string],
    "emerging_trends": [string],
    "recommended_upload_time": string
  },
  "title_variants": {
    "variants": [
      {"title": string, "ctr_score": number}
    ]
  }
}

All responses must be factual, realistic, and formatted as clean JSON.
PROMPT;

        $result = $this->callGemini($prompt);

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
                'raw_response' => $result['raw'] ?? null,
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
