<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoController extends Controller
{
    /**
     * 🔹 Fetch YouTube competitor data via SerpAPI
     */
    private function fetchSerpApiData(string $query): array
    {
        try {
            $response = Http::get('https://serpapi.com/search.json', [
                'engine' => 'youtube',
                'search_query' => $query,
                'api_key' => config('services.serpapi.key'),
            ]);

            $json = $response->json();

            if (!$response->successful() || !isset($json['video_results'])) {
                return ['error' => 'SerpAPI invalid response', 'raw' => $json];
            }

            $videos = collect($json['video_results'])
                ->map(fn ($v) => [
                    'title' => $v['title'] ?? 'Unknown',
                    'channel' => $v['channel']['name'] ?? 'Unknown',
                    'views' => isset($v['views'])
                        ? (int) str_replace(['views', ',', ' '], '', $v['views'])
                        : 0,
                ])
                ->filter(fn ($v) => $v['views'] > 0)
                ->take(10)
                ->values()
                ->toArray();

            $averageViews = count($videos)
                ? round(array_sum(array_column($videos, 'views')) / count($videos))
                : 0;

            $competitionLevel = $averageViews < 100000
                ? 'Low'
                : ($averageViews < 500000 ? 'Medium' : 'High');

            return [
                'competitors' => $videos,
                'average_views' => $averageViews,
                'competition_level' => $competitionLevel,
            ];

        } catch (\Throwable $e) {
            Log::error('SerpAPI Error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 🔹 Call Groq LLM
     */
    private function callGroq(string $prompt): array
    {
        try {
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . config('services.groq.key'),
                'Content-Type'  => 'application/json',
            ])->post(config('services.groq.url'), [
                'model' => 'llama-3.1-8b-instant',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Return ONLY valid JSON. No markdown. No explanations.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1500,
            ]);

            if (!$response->successful()) {
                Log::error('Groq HTTP Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['error' => 'Groq HTTP error', 'raw' => $response->json()];
            }

            $json = $response->json();

            if (!isset($json['choices'][0]['message']['content'])) {
                return ['error' => 'Groq malformed response', 'raw' => $json];
            }

            $raw = $json['choices'][0]['message']['content'];

            // Strip ```json wrappers
            $clean = preg_replace('/```(json)?|```/', '', $raw);
            $clean = trim($clean);

            $decoded = json_decode($clean, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                preg_match('/\{.*\}/s', $clean, $matches);
                $decoded = isset($matches[0]) ? json_decode($matches[0], true) : null;
            }

            return $decoded ?: ['error' => 'JSON parse failed', 'raw' => $clean];

        } catch (\Throwable $e) {
            Log::error('Groq Exception: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 🔹 Main Analyzer
     */
    public function analyze(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'geo' => 'nullable|string',
            'audience' => 'nullable|string',
        ]);

        // ---- PREP VALUES (FIX FOR HEREDOC) ----
        $title = $data['title'];
        $description = $data['description'] ?? 'Not provided';
        $audience = $data['audience'] ?? 'General viewers';
        $geo = $data['geo'] ?? 'Global';

        // ---- SERP API ----
        $serpData = $this->fetchSerpApiData($title);

        if (isset($serpData['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $serpData['error'],
                'raw_response' => $serpData['raw'] ?? null,
            ], 500);
        }

        $competitorsJson = json_encode($serpData['competitors'], JSON_PRETTY_PRINT);

        // ---- FULL FIXED PROMPT ----
 $prompt = <<<PROMPT
IMPORTANT RULES (STRICT):
- Fill EVERY field in the JSON
- NEVER leave strings empty
- NEVER omit any array items
- If data is missing, intelligently INFER it
- Assume this is a YouTube Shorts video
- Target a global audience
- Be SEO-focused, practical, and realistic
- Output ONLY valid JSON (no markdown, no text)

HASHTAG RULES (VERY IMPORTANT):
- Provide AT LEAST 12–20 hashtags
- Mix broad + niche + trending hashtags
- Include:
  • primary keyword hashtags
  • secondary keyword hashtags
  • Shorts-related hashtags (#shorts, #ytshorts, etc.)
  • engagement hashtags (#viral, #trending, #funny, etc.)
- Hashtags must be lowercase and without spaces
- Do NOT repeat the same hashtag

SCORING RULES:
- All SEO scores MUST be integers between 0 and 100
- 0–20 = very poor
- 21–40 = poor
- 41–60 = average
- 61–80 = good
- 81–100 = excellent
- Overall score MUST be a weighted average of other scores
- Do NOT give the same score to all fields

CTR RULES:
- ctr_score MUST be an integer between 0 and 100
- Higher CTR means more click-worthy titles
- Be consistent across all title variants

You are an expert YouTube SEO strategist.

REAL competitor data from YouTube:
{$competitorsJson}

Average Views: {$serpData['average_views']}
Competition Level: {$serpData['competition_level']}

Video Information:
Title: {$title}
Description: {$description}
Audience: {$audience}
Geo: {$geo}

Generate COMPLETE values for the following JSON structure:

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
    "search_intent": "Informational | Commercial | Trending | Entertainment",
    "competition_level": "Low | Medium | High",
    "volume_score": number
  },
  "competitor_analysis": {
    "top_competitors": [
      { "title": string, "channel": string, "views": number }
    ],
    "average_views": number,
    "competition_level": string,
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
      { "title": string, "ctr_score": number }
    ]
  }
}
PROMPT;

        // ---- GROQ ----
        $result = $this->callGroq($prompt);

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
                'raw_response' => $result['raw'] ?? null,
            ], 500);
        }

        // ---- NORMALIZE SCORES ----
        if (isset($result['seo_score_breakdown'])) {
    foreach ($result['seo_score_breakdown'] as $k => $v) {
        if (is_numeric($v)) {

            // Handle decimal (0–1) or percentage (0–100)
            if ($v <= 1) {
                $score = round($v * 100);
            } else {
                $score = round($v);
            }

            $result['seo_score_breakdown'][$k] = min(100, max(0, $score));
        }
    }
}


        return response()->json([
            'status' => 'success',
            'data' => $result,
            'competitors' => $serpData['competitors'],
            'average_views' => $serpData['average_views'],
            'competition_level' => $serpData['competition_level'],
        ]);
    }
}
