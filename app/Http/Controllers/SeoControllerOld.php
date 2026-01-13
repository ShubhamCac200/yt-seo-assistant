<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SeoController extends Controller
{
    /**
     * 🔹 Fetch real YouTube data (title, channel, views) via SerpAPI
     */
    private function fetchSerpApiData($query)
    {
        try {
            $response = Http::get('https://serpapi.com/search.json', [
                'engine' => 'youtube',
                'search_query' => $query,
                'api_key' => config('services.serpapi.key'),
            ]);

            $json = $response->json();

            if (!$response->successful() || !isset($json['video_results'])) {
                return ['error' => 'SerpAPI returned invalid response', 'raw' => $json];
            }

            // Extract relevant fields
            $videos = collect($json['video_results'])
                ->map(function ($video) {
                    return [
                        'title' => $video['title'] ?? 'Unknown Title',
                        'channel' => $video['channel']['name'] ?? 'Unknown',
                        'views' => isset($video['views'])
                            ? (int) str_replace(['views', ',', ' '], '', $video['views'])
                            : 0,
                    ];
                })
                ->filter(fn($v) => $v['views'] > 0)
                ->take(10)
                ->values()
                ->toArray();

            // Calculate average views
            $averageViews = count($videos)
                ? round(array_sum(array_column($videos, 'views')) / count($videos))
                : 0;

            // Determine competition strength
            $competitionLevel = $averageViews < 100000 ? 'Low' :
                ($averageViews < 500000 ? 'Medium' : 'High');

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
     * 🔹 Call Gemini API for SEO optimization
     */
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
            if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.*\}/s', $clean, $matches)) {
                $result = json_decode($matches[0], true);
            }

            return $result ?: ['error' => 'Empty or invalid JSON', 'raw' => $clean];
        } catch (\Throwable $e) {
            Log::error('Gemini Error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 🔹 Combined Analyzer: SerpAPI + Gemini
     */
    public function analyze(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'geo' => 'nullable|string',
            'audience' => 'nullable|string',
        ]);

        // 1️⃣ Fetch live competitor data
        $serpData = $this->fetchSerpApiData($data['title']);
        $competitorsJson = isset($serpData['competitors'])
            ? json_encode($serpData['competitors'], JSON_PRETTY_PRINT)
            : '[]';

        // 2️⃣ Create Gemini prompt using live context
        $prompt = <<<PROMPT
You are an expert YouTube SEO strategist.

You are given real-time competitor data fetched from YouTube via SerpAPI:
Competitors: {$competitorsJson}
Average Views: {$serpData['average_views']}
Competition Level: {$serpData['competition_level']}

Now analyze and optimize this video for maximum reach and engagement.

Title: {$data['title']}
Description: {$data['description']}
Audience: {$data['audience']}
Geo: {$data['geo']}

Return a single clean JSON response exactly in this format:
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
      {"title": string, "channel": string, "views": number}
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
      {"title": string, "ctr_score": number}
    ]
  }
}

Output only clean JSON, no markdown or text.
PROMPT;

        // 3️⃣ Call Gemini
        $result = $this->callGemini($prompt);

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['error'],
                'raw_response' => $result['raw'] ?? null,
            ], 500);
        }

        // 4️⃣ Final JSON response for frontend
        return response()->json([
            'status' => 'success',
            'data' => $result,
            'competitors' => $serpData['competitors'] ?? [],
            'average_views' => $serpData['average_views'] ?? 0,
            'competition_level' => $serpData['competition_level'] ?? 'Unknown',
        ]);
    }
}
