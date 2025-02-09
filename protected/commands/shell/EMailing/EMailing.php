<?php

/**
 * Profiler
 *  © 2015 Profiler
 */

/**
 * The E-Mailing command sends daily or hourly e-mails updates with current
 * pending activities and notification.
 *
 * @package profiler.commands.shell.EMailing
 * @since 0.5
 */
class EMailing extends HConsoleCommand
{

    private $mode = "hourly"; // daily

    /**
     * Run method for EMailing System
     *
     * @param type $args
     */

    public function run($args)
    {

        $this->printHeader('E-Mail Interface');

        if (!isset($args[0]) || ($args[0] != "daily" && $args[0] != 'hourly')) {
            print "\n Run with parameter:\n" .
                    "\t daily - for Daily Mailings\n" .
                    "\t hourly - for Hourly Mailings\n";
            print "\n\n";
            exit;
        }
        $this->mode = $args[0];
        Yii::import("application.modules_core.wall.*", true);

        $users = User::model()->with('httpSessions')->findAllByAttributes(array('status' => User::STATUS_ENABLED));

        // Save systems default language - before switching to users language
        $defaultLanguage = Yii::app()->language;
        
        foreach ($users as $user) {

            print "Processing : " . $user->email . ": ";

            // Switch to users language if set
            if ($user->language !== "") {
                Yii::app()->language = $user->language;
            } else {
                Yii::app()->language = $defaultLanguage;
            }
            
            $notificationContent = $this->getNotificationContent($user);
            $activityContent = $this->getActivityContent($user);

            // Something new?
            if ($notificationContent == "" && $activityContent == "") {
                print "Nothing new! \n";
                continue;
            }

            $message = new HMailMessage();
            $message->view = 'application.views.mail.EMailing';
            $message->addFrom(HSetting::Get('systemEmailAddress', 'mailing'), HSetting::Get('systemEmailName', 'mailing'));
            $message->addTo($user->email);

            if ($this->mode == 'hourly') {
                $message->subject = Yii::t('base', "Latest news");
            } else {
                $message->subject = Yii::t('base', "Your daily summary");
            }

            $message->setBody(array(
                'notificationContent' => $notificationContent,
                'activityContent' => $activityContent,
                'user' => $user,
                    ), 'text/html');
            Yii::app()->mail->send($message);

            print "Sent! \n";
        }

        print "\nEMailing completed.\n";
    }

    /**
     * Returns notification content by given user.
     *
     * The output will generated on current mode.
     *
     * @param type $user
     * @return string email output
     */
    private function getNotificationContent($user)
    {

        $receive_email_notifications = $user->getSetting("receive_email_notifications", 'core', HSetting::Get('receive_email_notifications', 'mailing'));


        // Never receive notifications
        if ($receive_email_notifications == User::RECEIVE_EMAIL_NEVER) {
            return "";
        }

        // We are in hourly mode and user wants daily
        if ($this->mode == 'hourly' && $receive_email_notifications == User::RECEIVE_EMAIL_DAILY_SUMMARY) {
            return "";
        }

        // We are in daily mode and user dont wants daily reports
        if ($this->mode == 'daily' && $receive_email_notifications != User::RECEIVE_EMAIL_DAILY_SUMMARY) {
            return "";
        }

        // User wants only when offline and is online
        if ($this->mode == 'hourly') {
            $isOnline = (count($user->httpSessions) > 0);
            if ($receive_email_notifications == User::RECEIVE_EMAIL_WHEN_OFFLINE && $isOnline) {
                return "";
            }
        }

        // Get not seen notifcation, order by created_at
        $criteria = new CDbCriteria();
        $criteria->order = 'created_at DESC';
        $notifications = Notification::model()->findAllByAttributes(array('user_id' => $user->id, 'seen' => 0, 'emailed' => 0), $criteria);

        // Nothin new
        if (count($notifications) == 0) {
            return "";
        }

        // Generate notification output
        $output = "";
        foreach ($notifications as $notification) {
            $output .= $notification->getMailOut();
            $notification->emailed = 1;
            $notification->save();
        }

        return $output;
    }

    /**
     * Return activity content by given user.
     *
     * This output is generated by current mode.
     *
     * @param type $user
     * @return string
     */
    private function getActivityContent($user)
    {

        $receive_email_activities = $user->getSetting("receive_email_activities", 'core', HSetting::Get('receive_email_activities', 'mailing'));

        // User never wants activity content
        if ($receive_email_activities == User::RECEIVE_EMAIL_NEVER) {
            return "";
        }

        // We are in hourly mode and user wants receive a daily summary
        if ($this->mode == 'hourly' && $receive_email_activities == User::RECEIVE_EMAIL_DAILY_SUMMARY) {
            return "";
        }

        // We are in daily mode and user wants receive not daily
        if ($this->mode == 'daily' && $receive_email_activities != User::RECEIVE_EMAIL_DAILY_SUMMARY) {
            return "";
        }

        // User is online and want only receive when offline
        if ($this->mode == 'hourly') {
            $isOnline = (count($user->httpSessions) > 0);
            if ($receive_email_activities == User::RECEIVE_EMAIL_WHEN_OFFLINE && $isOnline) {
                return "";
            }
        }


        $lastMailDate = $user->last_activity_email;
        if ($lastMailDate == "" || $lastMailDate == "0000-00-00 00:00:00") {
            $lastMailDate = new CDbExpression('NOW() - INTERVAL 24 HOUR');
        }


        // Get Stream contents
        $action = new StreamAction(null, 'console');
        $action->mode = StreamAction::MODE_ACTIVITY;
        $action->type = Wall::TYPE_DASHBOARD;
        $action->userId = $user->id;
        $action->userWallId = $user->wall_id;
        $action->wallEntryLimit = 50;
        $action->wallEntryDateTo = $lastMailDate;
        $activities = $action->runConsole();

        # Save last run
        $user->last_activity_email = new CDbExpression('NOW()');
        $user->save();

        // Nothin new
        if ($activities['counter'] == 0) {
            return "";
        }

        // Return Output
        return $activities['output'];
    }

}
