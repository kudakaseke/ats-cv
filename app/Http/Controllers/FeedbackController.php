<?php

namespace App\Http\Controllers;

use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    public function submit(Request $request, AuditTrail $audit)
    {
        $data = $request->validate(['category' => 'required|in:cv_analysis,account,billing,complaint,suggestion,other', 'rating' => 'nullable|integer|min:1|max:5', 'body' => 'required|string|min:10|max:3000', 'testimonial_consent' => 'nullable|boolean']);
        $id = DB::table('customer_feedback')->insertGetId(['user_id' => $request->user()->id, 'category' => $data['category'], 'rating' => $data['rating'] ?? null, 'body' => $data['body'], 'testimonial_consent' => (bool) ($data['testimonial_consent'] ?? false), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $audit->record($request->user()->id, 'feedback.submitted', 'CustomerFeedback', $id, ['category' => $data['category'], 'rating' => $data['rating'] ?? null]);
        return back()->with('status', 'Thank you. Your feedback has been sent for review.');
    }

    public function moderate(Request $request, int $feedback)
    {
        $row = DB::table('customer_feedback')->where('id',$feedback)->first();
        abort_unless($row, 404);
        $data = $request->validate(['status' => 'required|in:approved,rejected,pending', 'admin_reply' => 'nullable|string|max:3000']);
        DB::table('customer_feedback')->where('id',$feedback)->update([...$data,'moderated_by' => $request->user('admin')->id,'moderated_at' => now(),'updated_at' => now()]);
        app(\App\Services\Admin\AdminAuditTrail::class)->record('feedback.moderated','CustomerFeedback',$feedback,'Feedback moderation by super admin.',['status' => $row->status],['status' => $data['status'],'reply_added' => filled($data['admin_reply'] ?? null)]);
        app(\App\Services\AuditTrail::class)->record((int) $row->user_id,'feedback.moderated','CustomerFeedback',$feedback,['actor_admin_id' => $request->user('admin')->id,'status' => $data['status']]);
        return back()->with('status','Feedback moderation saved and linked to your super-admin account.');
    }
}
